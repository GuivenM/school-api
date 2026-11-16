<?php

declare(strict_types=1);

namespace App\Controller;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Backup\SauvegardeService;
use App\Entity\TypeSauvegarde;
use App\Tenant\Calcul\ClotureAnnee;
use App\Tenant\Calcul\ClotureImpossibleException;
use App\Tenant\Entity\AnneeScolaire;
use App\Tenant\Entity\Classe;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clôture d'année. Corps (optionnel pour la simulation) :
 * {"anneeSuivante": "/api/annees_scolaires/2",
 *  "affectations": [{"classeSource": "/api/classes/1", "admis": "/api/classes/10", "redoublants": "/api/classes/11"}]}
 * « affectations » n'est nécessaire que lorsque l'année suivante a plusieurs classes d'un même niveau.
 */
final class ClotureAnneeController
{
    public function __construct(
        private readonly ClotureAnnee $cloture,
        private readonly IriConverterInterface $iriConverter,
        private readonly SauvegardeService $sauvegardes,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:BACKUP_ON_CLOSURE)%')]
        private readonly bool $sauvegarderALaCloture,
    ) {
    }

    /** Simulation : ne modifie rien. */
    #[Route('/api/annees_scolaires/{id}/cloture/apercu', name: 'api_annee_cloture_apercu', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SECRETARIAT')]
    public function apercu(AnneeScolaire $annee, Request $request): JsonResponse
    {
        try {
            [$suivante, $affectations] = $this->lireDemande($request);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $plan = $this->cloture->planifier($annee, $suivante, $affectations);
        unset($plan['actions']);

        return new JsonResponse([
            'annee' => $annee->getId(),
            'anneeSuivante' => $suivante?->getId(),
            'pret' => [] === $plan['blocages'],
        ] + $plan);
    }

    /**
     * Exécution (directeur) : irréversible. Tout ou rien. Avec BACKUP_ON_CLOSURE=1, la base est sauvegardée AVANT (si cela échoue,
     * la clôture est refusée : c'est le point de retour) puis archivée APRÈS la clôture.
     */
    #[Route('/api/annees_scolaires/{id}/cloturer', name: 'api_annee_cloturer', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_DIRECTEUR')]
    public function cloturer(AnneeScolaire $annee, Request $request): JsonResponse
    {
        try {
            [$suivante, $affectations] = $this->lireDemande($request);

            // On vérifie d'abord qu'il n'y a aucun blocage, pour ne pas sauvegarder inutilement à chaque tentative refusée.
            $plan = $this->cloture->planifier($annee, $suivante, $affectations);
            if ([] !== $plan['blocages']) {
                throw new ClotureImpossibleException($plan['blocages']);
            }

            if ($this->sauvegarderALaCloture) {
                try {
                    $this->sauvegardes->sauvegarderEcoleCourante(TypeSauvegarde::AvantCloture, 'Avant clôture de '.$annee->getLibelle(), $annee->getLibelle());
                } catch (\Throwable $e) {
                    $this->logger->error('Sauvegarde préalable à la clôture échouée', ['exception' => $e]);

                    return new JsonResponse(['error' => 'Clôture annulée : la sauvegarde préalable a échoué. Contactez l\'administrateur de la plateforme.'], 503);
                }
            }

            $resume = $this->cloture->cloturer($annee, $suivante, $affectations);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (ClotureImpossibleException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'blocages' => $e->blocages], 409);
        }

        $archive = null;
        if ($this->sauvegarderALaCloture) {
            try {
                $sauvegarde = $this->sauvegardes->sauvegarderEcoleCourante(TypeSauvegarde::ArchiveAnnee, 'Archive de l\'année '.$annee->getLibelle(), $annee->getLibelle());
                $archive = ['id' => $sauvegarde->getId(), 'destinations' => $sauvegarde->getDestinations()];
            } catch (\Throwable $e) {
                $this->logger->error('Archive de fin d\'année échouée', ['exception' => $e]);
                $archive = ['erreur' => 'L\'archive n\'a pas pu être créée : l\'administrateur de la plateforme doit relancer une sauvegarde.'];
            }
        }

        return new JsonResponse([
            'annee' => $annee->getId(),
            'statut' => $annee->getStatut()->value,
            'anneeSuivante' => $suivante?->getId(),
            'resume' => $resume,
            'archive' => $archive,
        ]);
    }

    /** @return array{0: ?AnneeScolaire, 1: array<int, array<string, ?int>>} */
    private function lireDemande(Request $request): array
    {
        $corps = '' === $request->getContent() ? [] : json_decode($request->getContent(), true);
        if (!\is_array($corps)) {
            throw new \InvalidArgumentException('Corps JSON invalide.');
        }

        $suivante = null;
        if (isset($corps['anneeSuivante'])) {
            $suivante = $this->resoudre($corps['anneeSuivante'], AnneeScolaire::class, 'anneeSuivante');
        }

        $affectations = [];
        foreach ($corps['affectations'] ?? [] as $ligne) {
            if (!\is_array($ligne) || !isset($ligne['classeSource'])) {
                throw new \InvalidArgumentException('Chaque affectation doit contenir « classeSource ».');
            }
            $source = $this->resoudre($ligne['classeSource'], Classe::class, 'classeSource');
            foreach (['admis', 'redoublants'] as $cle) {
                if (isset($ligne[$cle])) {
                    $affectations[$source->getId()][$cle] = $this->resoudre($ligne[$cle], Classe::class, $cle)->getId();
                }
            }
        }

        return [$suivante, $affectations];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(mixed $iri, string $classe, string $champ): object
    {
        try {
            $ressource = \is_string($iri) ? $this->iriConverter->getResourceFromIri($iri) : null;
        } catch (\Throwable) {
            $ressource = null;
        }
        if (!$ressource instanceof $classe) {
            throw new \InvalidArgumentException(sprintf('« %s » doit être l\'IRI d\'une ressource existante du bon type.', $champ));
        }

        return $ressource;
    }
}
