<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiPlatform\Acces;
use App\Tenant\Entity\Appreciation;
use App\Tenant\Entity\ClasseMatiere;
use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\Matiere;
use App\Tenant\Entity\Periode;
use App\Tenant\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Appréciations d'un élève pour un trimestre (qui s'impriment sur le bulletin).
 *  - « generale » : le secrétariat/directeur ou le titulaire de la classe ;
 *  - « matieres » : le secrétariat/directeur ou l'enseignant de cette matière dans la classe.
 * Modifiables tant que le trimestre est ouvert.
 */
final class AppreciationController
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[Route('/api/inscriptions/{id}/appreciations/{periode}', name: 'api_appreciations_lire', methods: ['GET'], requirements: ['id' => '\d+', 'periode' => '\d+'])]
    #[IsGranted(new Expression(Acces::PERSONNEL))]
    public function lire(#[MapEntity(id: 'id')] Inscription $inscription, #[MapEntity(id: 'periode')] Periode $periode): JsonResponse
    {
        return new JsonResponse($this->presenter($inscription, $periode));
    }

    /**
     * PUT {"generale": "texte" | null, "matieres": {"<id de matière>": "texte" | null}}
     * Une clé absente n'est pas touchée ; null ou texte vide supprime l'appréciation. Tout ou rien.
     */
    #[Route('/api/inscriptions/{id}/appreciations/{periode}', name: 'api_appreciations_ecrire', methods: ['PUT'], requirements: ['id' => '\d+', 'periode' => '\d+'])]
    #[IsGranted(new Expression(Acces::SAISIE_PREALABLE))]
    public function ecrire(
        #[MapEntity(id: 'id')] Inscription $inscription,
        #[MapEntity(id: 'periode')] Periode $periode,
        Request $request,
        #[CurrentUser] Utilisateur $utilisateur,
    ): JsonResponse {
        if ($periode->getAnnee() !== $inscription->getAnnee()) {
            return new JsonResponse(['error' => 'Ce trimestre n\'appartient pas à l\'année de cette inscription.'], 400);
        }
        if (!$periode->estOuverte()) {
            return new JsonResponse(['error' => 'Ce trimestre est fermé : les appréciations ne peuvent plus être modifiées.'], 409);
        }

        $corps = json_decode($request->getContent(), true);
        if (!\is_array($corps) || (!\array_key_exists('generale', $corps) && !\array_key_exists('matieres', $corps))) {
            return new JsonResponse(['error' => 'Corps attendu : {"generale": ..., "matieres": {...}}.'], 400);
        }

        $classe = $inscription->getClasse();
        $secretariat = $this->authorization->isGranted('ROLE_SECRETARIAT');
        $enseignant = $utilisateur->getEnseignant();

        /** @var list<array{0: ?Matiere, 1: ?string}> $changements */
        $changements = [];

        if (\array_key_exists('generale', $corps)) {
            if (!$secretariat && (null === $enseignant || $classe->getTitulaire() !== $enseignant)) {
                throw new AccessDeniedException('Seul le titulaire de la classe peut rédiger l\'appréciation générale.');
            }
            $changements[] = [null, $this->nettoyer($corps['generale'], 'generale', $erreur)];
        }

        foreach (\is_array($corps['matieres'] ?? null) ? $corps['matieres'] : [] as $matiereId => $texte) {
            $classeMatiere = $this->tenantEntityManager->getRepository(ClasseMatiere::class)->findOneBy(['classe' => $classe, 'matiere' => (int) $matiereId]);
            if (null === $classeMatiere) {
                return new JsonResponse(['error' => sprintf('La matière %s n\'est pas enseignée dans cette classe.', $matiereId)], 422);
            }
            if (!$secretariat && (null === $enseignant || $classeMatiere->getEnseignant() !== $enseignant)) {
                throw new AccessDeniedException('Vous n\'êtes pas l\'enseignant de cette matière dans cette classe.');
            }
            $changements[] = [$classeMatiere->getMatiere(), $this->nettoyer($texte, (string) $matiereId, $erreur)];
        }

        if (isset($erreur)) {
            return new JsonResponse(['error' => $erreur], 422);
        }

        $repository = $this->tenantEntityManager->getRepository(Appreciation::class);
        foreach ($changements as [$matiere, $texte]) {
            $existante = $repository->findOneBy(['inscription' => $inscription, 'periode' => $periode, 'matiere' => $matiere]);
            if (null === $texte) {
                null !== $existante && $this->tenantEntityManager->remove($existante);
                continue;
            }
            $appreciation = $existante ?? new Appreciation($inscription, $periode, $matiere);
            $appreciation->modifier($texte, $utilisateur->getUserIdentifier());
            $this->tenantEntityManager->persist($appreciation);
        }
        $this->tenantEntityManager->flush();

        return new JsonResponse($this->presenter($inscription, $periode));
    }

    /** @param-out string $erreur */
    private function nettoyer(mixed $texte, string $champ, ?string &$erreur): ?string
    {
        if (null === $texte) {
            return null;
        }
        if (!\is_string($texte)) {
            $erreur = sprintf('« %s » doit être un texte ou null.', $champ);

            return null;
        }

        $texte = trim($texte);
        if (mb_strlen($texte) > Appreciation::LONGUEUR_MAX) {
            $erreur = sprintf('« %s » dépasse %d caractères.', $champ, Appreciation::LONGUEUR_MAX);

            return null;
        }

        return '' === $texte ? null : $texte;
    }

    /** @return array<string, mixed> */
    private function presenter(Inscription $inscription, Periode $periode): array
    {
        $generale = null;
        $matieres = [];
        foreach ($this->tenantEntityManager->getRepository(Appreciation::class)->findBy(['inscription' => $inscription, 'periode' => $periode]) as $appreciation) {
            if (null === $appreciation->getMatiere()) {
                $generale = $appreciation->getTexte();
            } else {
                $matieres[$appreciation->getMatiere()->getId()] = $appreciation->getTexte();
            }
        }

        return [
            'inscription' => $inscription->getId(),
            'periode' => $periode->getId(),
            'statutPeriode' => $periode->getStatut()->value,
            'generale' => $generale,
            'matieres' => (object) $matieres,
        ];
    }
}
