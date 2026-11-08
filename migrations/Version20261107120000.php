<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Base centrale (landlord) : table des écoles. */
final class Version20261107120000 extends AbstractMigration
{
    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Landlord : table tenants';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('tenants');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('slug', Types::STRING, ['length' => 28]);
        $table->addColumn('name', Types::STRING, ['length' => 150]);
        $table->addColumn('db_name', Types::STRING, ['length' => 64]);
        $table->addColumn('db_user', Types::STRING, ['length' => 32]);
        $table->addColumn('db_password_enc', Types::TEXT);
        $table->addColumn('status', Types::STRING, ['length' => 20]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('activated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['slug'], 'uniq_tenants_slug');
        $table->addUniqueIndex(['db_name'], 'uniq_tenants_db_name');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tenants');
    }
}
