<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108100000 extends AbstractMigration
{
    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Landlord : table super_admins';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('super_admins');
        $table->addOption('engine', 'InnoDB');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('email', Types::STRING, ['length' => 180]);
        $table->addColumn('password', Types::STRING, ['length' => 255]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['email'], 'uniq_super_admins_email');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('super_admins');
    }
}
