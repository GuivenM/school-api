<?php

declare(strict_types=1);

namespace App\Tenancy\Doctrine;

use App\Tenancy\TenantContext;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Enregistré sur la connexion "tenant" uniquement (tag doctrine.middleware, voir services.yaml).
 */
final class TenantDriverMiddleware implements Middleware
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly bool $allowTemplateFallback = false,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TenantDriver($driver, $this->context, $this->allowTemplateFallback);
    }
}
