<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Base centrale : journal des sauvegardes d'écoles. */
final class Version20261113200100 extends AbstractMigration
{
    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Landlord : table sauvegardes';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('sauvegardes');
        $table->addOption('engine', 'InnoDB');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('tenant_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('tenant_slug', Types::STRING, ['length' => 28]);
        $table->addColumn('type', Types::STRING, ['length' => 30]);
        $table->addColumn('chemin', Types::STRING, ['length' => 255]);
        $table->addColumn('taille', Types::INTEGER);
        $table->addColumn('sha256', Types::STRING, ['length' => 64]);
        $table->addColumn('destinations', Types::JSON);
        $table->addColumn('destinations_echouees', Types::JSON);
        $table->addColumn('annee_libelle', Types::STRING, ['length' => 20, 'notnull' => false]);
        $table->addColumn('note', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint('tenants', ['tenant_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('sauvegardes');
    }
}
