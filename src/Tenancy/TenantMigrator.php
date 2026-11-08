<?php

declare(strict_types=1);

namespace App\Tenancy;

use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique les migrations du dossier tenant_migrations/ sur la base de l'école ACTIVE
 * (le contexte doit avoir été positionné avec TenantSwitcher::switchTo()).
 */
final class TenantMigrator
{
    public function __construct(
        private readonly EntityManagerInterface $tenantEntityManager,
        private readonly string $migrationsDir,
    ) {
    }

    /** @return int nombre de migrations exécutées */
    public function migrate(): int
    {
        $config = new ConfigurationArray([
            'migrations_paths' => ['TenantMigrations' => $this->migrationsDir],
            'table_storage' => ['table_name' => 'doctrine_migration_versions'],
        ]);

        $factory = DependencyFactory::fromEntityManager($config, new ExistingEntityManager($this->tenantEntityManager));
        $factory->getMetadataStorage()->ensureInitialized();

        $latest = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($latest);

        if (0 === \count($plan)) {
            return 0;
        }

        $factory->getMigrator()->migrate($plan, new MigratorConfiguration());

        return \count($plan);
    }
}
