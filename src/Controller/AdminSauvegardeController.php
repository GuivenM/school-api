<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backup\SauvegardeService;
use App\Entity\Sauvegarde;
use App\Entity\Tenant;
use App\Entity\TypeSauvegarde;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Sauvegardes vues par le super-admin de la plateforme (la restauration reste une opération en console). */
final class AdminSauvegardeController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SauvegardeService $sauvegardes,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/sauvegardes', name: 'admin_sauvegardes', methods: ['GET'])]
    public function lister(Request $request): JsonResponse
    {
        $slug = $request->query->get('tenant');
        $liste = $this->entityManager->getRepository(Sauvegarde::class)->findBy(
            \is_string($slug) && '' !== $slug ? ['tenantSlug' => $slug] : [],
            ['id' => 'DESC'],
            min(200, max(1, $request->query->getInt('limit', 50))),
        );

        return new JsonResponse(array_map(static fn (Sauvegarde $s): array => [
            'id' => $s->getId(),
            'tenant' => $s->getTenantSlug(),
            'type' => $s->getType()->value,
            'creeLe' => $s->getCreatedAt()->format(\DATE_ATOM),
            'taille' => $s->getTaille(),
            'destinations' => $s->getDestinations(),
            'destinationsEchouees' => $s->getDestinationsEchouees(),
            'annee' => $s->getAnneeLibelle(),
            'note' => $s->getNote(),
        ], $liste));
    }

    #[Route('/admin/tenants/{slug}/sauvegardes', name: 'admin_tenant_sauvegarder', methods: ['POST'], requirements: ['slug' => '[a-z0-9_]+'])]
    public function sauvegarder(string $slug): JsonResponse
    {
        $tenant = $this->entityManager->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);
        if (null === $tenant) {
            return new JsonResponse(['error' => 'École inconnue.'], 404);
        }

        try {
            $s = $this->sauvegardes->sauvegarder($tenant, TypeSauvegarde::Manuelle, 'Demandée par le super-admin');
        } catch (\Throwable $e) {
            $this->logger->error('Sauvegarde manuelle échouée', ['tenant' => $slug, 'exception' => $e]);

            return new JsonResponse(['error' => 'La sauvegarde a échoué (voir les journaux du serveur).'], 503);
        }

        return new JsonResponse(['id' => $s->getId(), 'destinations' => $s->getDestinations(), 'destinationsEchouees' => $s->getDestinationsEchouees()], 201);
    }
}
