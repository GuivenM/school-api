<?php

declare(strict_types=1);

namespace TenantMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108100100 extends AbstractMigration
{
    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Tenant : table utilisateurs';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('utilisateurs');
        $table->addOption('engine', 'InnoDB');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('email', Types::STRING, ['length' => 180]);
        $table->addColumn('password', Types::STRING, ['length' => 255]);
        $table->addColumn('role', Types::STRING, ['length' => 20]);
        $table->addColumn('actif', Types::BOOLEAN);
        $table->addColumn('nom', Types::STRING, ['length' => 100, 'notnull' => false]);
        $table->addColumn('prenoms', Types::STRING, ['length' => 150, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['email'], 'uniq_utilisateurs_email');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('utilisateurs');
    }
}
