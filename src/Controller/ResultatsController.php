<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiPlatform\Acces;
use App\Tenant\Calcul\CalculMoyennes;
use App\Tenant\Entity\Classe;
use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\Periode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Lecture des moyennes, rangs et bulletins (personnel de l'école). */
final class ResultatsController
{
    public function __construct(
        private readonly CalculMoyennes $calcul,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    /** Résultats d'une classe pour un trimestre : GET /api/periodes/{id}/resultats?classe=<id> */
    #[Route('/api/periodes/{id}/resultats', name: 'api_periode_resultats', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(Acces::PERSONNEL))]
    public function classe(Periode $periode, Request $request): JsonResponse
    {
        $classe = $this->tenantEntityManager->getRepository(Classe::class)->find($request->query->getInt('classe'));
        if (null === $classe || $classe->getAnnee() !== $periode->getAnnee()) {
            return new JsonResponse(['error' => 'Paramètre « classe » manquant, inconnu ou d\'une autre année scolaire.'], 400);
        }

        $lignes = $this->calcul->resultatsClasse($periode, $classe);

        return new JsonResponse([
            'periode' => $periode->getId(),
            'classe' => $classe->getId(),
            'statut' => $periode->getStatut()->value,
            'source' => $this->calcul->source($periode),
            'effectif' => \count(array_filter($lignes, static fn (array $l): bool => null !== $l['moyenne'])),
            'eleves' => $lignes,
        ]);
    }

    /** Bulletin d'un élève : GET /api/inscriptions/{id}/bulletin */
    #[Route('/api/inscriptions/{id}/bulletin', name: 'api_inscription_bulletin', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(Acces::PERSONNEL))]
    public function bulletin(Inscription $inscription): JsonResponse
    {
        return new JsonResponse($this->calcul->bulletin($inscription));
    }
}
