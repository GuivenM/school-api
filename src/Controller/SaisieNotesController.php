<?php

declare(strict_types=1);

namespace App\Controller;

use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiPlatform\Acces;
use App\Tenant\Entity\Evaluation;
use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\Note;
use App\Tenant\Entity\StatutNote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Saisie d'une classe entière en une requête (tout ou rien) :
 * POST /api/evaluations/{id}/notes
 * {"notes": [{"inscription": "/api/inscriptions/12", "statut": "saisie", "valeur": 14.5}, {"inscription": "/api/inscriptions/13", "statut": "absent"}]}
 * Une note déjà existante pour (évaluation, élève) est remplacée.
 */
final class SaisieNotesController
{
    private const MAX_NOTES = 300;

    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
        private readonly IriConverterInterface $iriConverter,
        private readonly ValidatorInterface $validator,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[Route('/api/evaluations/{id}/notes', name: 'api_evaluation_notes', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(Evaluation $evaluation, Request $request): JsonResponse
    {
        if (!$this->authorization->isGranted(new Expression(Acces::SAISIE_PREALABLE))
            || !$this->authorization->isGranted(new Expression(Acces::SAISIE_EVALUATION), $evaluation)) {
            throw new AccessDeniedException();
        }

        $body = json_decode($request->getContent(), true);
        $items = \is_array($body) ? ($body['notes'] ?? null) : null;
        if (!\is_array($items) || [] === $items || \count($items) > self::MAX_NOTES) {
            return new JsonResponse(['error' => sprintf('« notes » doit être une liste de 1 à %d éléments.', self::MAX_NOTES)], 400);
        }

        $repository = $this->tenantEntityManager->getRepository(Note::class);
        $notes = [];
        $erreurs = [];
        $vus = [];

        foreach (array_values($items) as $index => $item) {
            $inscription = $this->resoudreInscription($item);
            $statut = \is_array($item) ? StatutNote::tryFrom((string) ($item['statut'] ?? 'saisie')) : null;
            $valeur = \is_array($item) ? ($item['valeur'] ?? null) : null;

            if (null === $inscription) {
                $erreurs[] = ['index' => $index, 'champ' => 'inscription', 'message' => 'Inscription inconnue ou invalide.'];
                continue;
            }
            if (null === $statut) {
                $erreurs[] = ['index' => $index, 'champ' => 'statut', 'message' => 'Statut inconnu (saisie, absent, dispense, non_evalue).'];
                continue;
            }
            if (null !== $valeur && !is_numeric($valeur)) {
                $erreurs[] = ['index' => $index, 'champ' => 'valeur', 'message' => 'La valeur doit être un nombre.'];
                continue;
            }
            if (isset($vus[$inscription->getId()])) {
                $erreurs[] = ['index' => $index, 'champ' => 'inscription', 'message' => 'Cet élève apparaît deux fois dans la liste.'];
                continue;
            }
            $vus[$inscription->getId()] = true;

            $note = $repository->findOneBy(['evaluation' => $evaluation, 'inscription' => $inscription]) ?? new Note();
            $note->setEvaluation($evaluation);
            $note->setInscription($inscription);
            $note->setStatut($statut);
            $note->setValeur(null === $valeur ? null : (float) $valeur);

            foreach ($this->validator->validate($note) as $violation) {
                $erreurs[] = ['index' => $index, 'champ' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
            }
            $notes[] = $note;
        }

        if ([] !== $erreurs) {
            // Rien n'est enregistré : l'EntityManager n'est pas vidé (flush) et sera abandonné avec la requête.
            return new JsonResponse(['error' => 'Notes invalides : aucune note n\'a été enregistrée.', 'violations' => $erreurs], 422);
        }

        foreach ($notes as $note) {
            $this->tenantEntityManager->persist($note);
        }
        $this->tenantEntityManager->flush();

        return new JsonResponse([
            'evaluation' => $evaluation->getId(),
            'enregistrees' => \count($notes),
            'notes' => array_map(static fn (Note $n): array => [
                'id' => $n->getId(),
                'inscription' => $n->getInscription()->getId(),
                'statut' => $n->getStatut()->value,
                'valeur' => $n->getValeur(),
            ], $notes),
        ]);
    }

    private function resoudreInscription(mixed $item): ?Inscription
    {
        $iri = \is_array($item) ? ($item['inscription'] ?? null) : null;
        if (!\is_string($iri)) {
            return null;
        }

        try {
            $resource = $this->iriConverter->getResourceFromIri($iri);
        } catch (\Throwable) {
            return null;
        }

        return $resource instanceof Inscription ? $resource : null;
    }
}
