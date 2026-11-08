<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Diagnostic multi-tenant, disponible uniquement en dev et test. */
final class TenantPingController
{
    public function __construct(
        private readonly TenantContext $context,
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $tenantConnection,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[Route('/_tenant/ping', name: 'tenant_ping', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        if (!\in_array($this->environment, ['dev', 'test'], true)) {
            throw new NotFoundHttpException();
        }

        $slug = $this->context->getSlug();
        if (null === $slug) {
            return new JsonResponse(['error' => 'En-tête X-Tenant requis.'], 400);
        }

        $nomEcole = $this->tenantConnection->fetchOne('SELECT valeur FROM parametres WHERE cle = ?', ['nom_ecole']);

        return new JsonResponse([
            'tenant' => $slug,
            'database' => $this->tenantConnection->fetchOne('SELECT DATABASE()'),
            'nom_ecole' => false === $nomEcole ? null : $nomEcole,
        ]);
    }
}
