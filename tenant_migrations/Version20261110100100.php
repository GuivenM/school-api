<?php

declare(strict_types=1);

namespace TenantMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Inscriptions (parcours annuel de l'élève) et compteurs de matricules. */
final class Version20261110100100 extends AbstractMigration
{
    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Tenant : sequences et inscriptions';
    }

    public function up(Schema $schema): void
    {
        $sequences = $schema->createTable('sequences');
        $sequences->addOption('engine', 'InnoDB');
        $sequences->addColumn('cle', Types::STRING, ['length' => 50]);
        $sequences->addColumn('valeur', Types::INTEGER);
        $sequences->setPrimaryKey(['cle']);

        $inscriptions = $schema->createTable('inscriptions');
        $inscriptions->addOption('engine', 'InnoDB');
        $inscriptions->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $inscriptions->addColumn('eleve_id', Types::INTEGER);
        $inscriptions->addColumn('annee_id', Types::INTEGER);
        $inscriptions->addColumn('classe_id', Types::INTEGER);
        $inscriptions->addColumn('type', Types::STRING, ['length' => 20]);
        $inscriptions->addColumn('statut', Types::STRING, ['length' => 20]);
        $inscriptions->addColumn('date_inscription', Types::DATE_IMMUTABLE);
        $inscriptions->addColumn('date_sortie', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $inscriptions->addColumn('motif_sortie', Types::STRING, ['length' => 255, 'notnull' => false]);
        $inscriptions->addColumn('provenance', Types::STRING, ['length' => 255, 'notnull' => false]);
        $inscriptions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $inscriptions->setPrimaryKey(['id']);
        $inscriptions->addUniqueIndex(['eleve_id', 'annee_id'], 'uniq_inscriptions_eleve_annee');
        $inscriptions->addForeignKeyConstraint('eleves', ['eleve_id'], ['id'], ['onDelete' => 'RESTRICT']);
        $inscriptions->addForeignKeyConstraint('annees_scolaires', ['annee_id'], ['id'], ['onDelete' => 'RESTRICT']);
        $inscriptions->addForeignKeyConstraint('classes', ['classe_id'], ['id'], ['onDelete' => 'RESTRICT']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('inscriptions');
        $schema->dropTable('sequences');
    }
}
