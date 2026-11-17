<?php

declare(strict_types=1);

namespace TenantMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Appréciations des bulletins (générale et par matière). */
final class Version20261113100100 extends AbstractMigration
{
    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Tenant : appreciations';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('appreciations');
        $table->addOption('engine', 'InnoDB');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('inscription_id', Types::INTEGER);
        $table->addColumn('periode_id', Types::INTEGER);
        $table->addColumn('matiere_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('texte', Types::STRING, ['length' => 500]);
        $table->addColumn('auteur', Types::STRING, ['length' => 180]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['inscription_id', 'periode_id', 'matiere_id'], 'uniq_appreciations_inscription_periode_matiere');
        $table->addForeignKeyConstraint('inscriptions', ['inscription_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('periodes', ['periode_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('matieres', ['matiere_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('appreciations');
    }
}
