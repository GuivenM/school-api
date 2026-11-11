<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenant\Calcul\ClotureTrimestre;
use App\Tenant\Entity\Periode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ClotureTrimestreController
{
    public function __construct(private readonly ClotureTrimestre $cloture)
    {
    }

    /** Fige les résultats de toutes les classes et verrouille la saisie des notes du trimestre. */
    #[Route('/api/periodes/{id}/fermer', name: 'api_periode_fermer', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SECRETARIAT')]
    public function fermer(Periode $periode): JsonResponse
    {
        try {
            $nombre = $this->cloture->fermer($periode);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        return new JsonResponse(['periode' => $periode->getId(), 'statut' => $periode->getStatut()->value, 'resultats' => $nombre]);
    }

    /** Annule la fermeture (directeur) : les résultats figés sont supprimés, la saisie est de nouveau possible. */
    #[Route('/api/periodes/{id}/rouvrir', name: 'api_periode_rouvrir', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_DIRECTEUR')]
    public function rouvrir(Periode $periode): JsonResponse
    {
        try {
            $this->cloture->rouvrir($periode);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        return new JsonResponse(['periode' => $periode->getId(), 'statut' => $periode->getStatut()->value]);
    }
}
