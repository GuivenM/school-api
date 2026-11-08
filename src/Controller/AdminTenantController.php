<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AdminTenantController
{
    #[Route('/admin/tenants', name: 'admin_tenants', methods: ['GET'])]
    public function list(EntityManagerInterface $entityManager): JsonResponse
    {
        $tenants = [];
        foreach ($entityManager->getRepository(Tenant::class)->findBy([], ['slug' => 'ASC']) as $tenant) {
            $tenants[] = [
                'slug' => $tenant->getSlug(),
                'name' => $tenant->getName(),
                'status' => $tenant->getStatus()->value,
                'createdAt' => $tenant->getCreatedAt()->format(\DATE_ATOM),
            ];
        }

        return new JsonResponse($tenants);
    }
}
