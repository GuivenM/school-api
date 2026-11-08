<?php

declare(strict_types=1);

namespace TenantMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Exécutée dans CHAQUE base d'école (tenant:create, tenant:migrate). */
final class Version20261107120100 extends AbstractMigration
{
    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Tenant : table parametres';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('parametres');
        $table->addColumn('cle', Types::STRING, ['length' => 100]);
        $table->addColumn('valeur', Types::TEXT, ['notnull' => false]);
        $table->setPrimaryKey(['cle']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('parametres');
    }
}
