<?php

declare(strict_types=1);

namespace App\Tenancy\Doctrine;

use App\Tenancy\Exception\NoActiveTenantException;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Remplace dbname / user / password au moment de la connexion réelle, selon l'école active.
 * Sans école active, la connexion est REFUSÉE (fail closed), sauf mode "template" explicite (dev).
 */
final class TenantDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly TenantContext $context,
        private readonly bool $allowTemplateFallback,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): DriverConnection
    {
        $credentials = $this->context->get();

        if (null === $credentials) {
            if (!$this->allowTemplateFallback) {
                throw new NoActiveTenantException('Aucune école active : connexion à la base tenant refusée.');
            }

            return parent::connect($params);
        }

        $params['dbname'] = $credentials->dbName;
        $params['user'] = $credentials->dbUser;
        $params['password'] = $credentials->dbPassword;

        return parent::connect($params);
    }
}
