<?php

declare(strict_types=1);

namespace TenantMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Référentiel : années, périodes (trimestres), niveaux, matières, classes, classe_matieres. */
final class Version20261108200100 extends AbstractMigration
{
    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Tenant : référentiel (années, périodes, niveaux, matières, classes, classe_matieres)';
    }

    public function up(Schema $schema): void
    {
        $annees = $schema->createTable('annees_scolaires');
        $annees->addOption('engine', 'InnoDB');
        $annees->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $annees->addColumn('libelle', Types::STRING, ['length' => 20]);
        $annees->addColumn('date_debut', Types::DATE_IMMUTABLE);
        $annees->addColumn('date_fin', Types::DATE_IMMUTABLE);
        $annees->addColumn('statut', Types::STRING, ['length' => 20]);
        $annees->addColumn('cloturee_le', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $annees->setPrimaryKey(['id']);
        $annees->addUniqueIndex(['libelle'], 'uniq_annees_scolaires_libelle');

        $niveaux = $schema->createTable('niveaux');
        $niveaux->addOption('engine', 'InnoDB');
        $niveaux->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $niveaux->addColumn('code', Types::STRING, ['length' => 20]);
        $niveaux->addColumn('libelle', Types::STRING, ['length' => 100]);
        $niveaux->addColumn('ordre', Types::INTEGER);
        $niveaux->addColumn('niveau_suivant_id', Types::INTEGER, ['notnull' => false]);
        $niveaux->setPrimaryKey(['id']);
        $niveaux->addUniqueIndex(['code'], 'uniq_niveaux_code');
        $niveaux->addForeignKeyConstraint('niveaux', ['niveau_suivant_id'], ['id'], ['onDelete' => 'SET NULL']);

        $matieres = $schema->createTable('matieres');
        $matieres->addOption('engine', 'InnoDB');
        $matieres->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $matieres->addColumn('code', Types::STRING, ['length' => 20]);
        $matieres->addColumn('libelle', Types::STRING, ['length' => 100]);
        $matieres->setPrimaryKey(['id']);
        $matieres->addUniqueIndex(['code'], 'uniq_matieres_code');

        $periodes = $schema->createTable('periodes');
        $periodes->addOption('engine', 'InnoDB');
        $periodes->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $periodes->addColumn('annee_id', Types::INTEGER);
        $periodes->addColumn('numero', Types::SMALLINT);
        $periodes->addColumn('libelle', Types::STRING, ['length' => 50]);
        $periodes->addColumn('date_debut', Types::DATE_IMMUTABLE);
        $periodes->addColumn('date_fin', Types::DATE_IMMUTABLE);
        $periodes->addColumn('statut', Types::STRING, ['length' => 20]);
        $periodes->setPrimaryKey(['id']);
        $periodes->addUniqueIndex(['annee_id', 'numero'], 'uniq_periodes_annee_numero');
        $periodes->addForeignKeyConstraint('annees_scolaires', ['annee_id'], ['id'], ['onDelete' => 'CASCADE']);

        $classes = $schema->createTable('classes');
        $classes->addOption('engine', 'InnoDB');
        $classes->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $classes->addColumn('annee_id', Types::INTEGER);
        $classes->addColumn('niveau_id', Types::INTEGER);
        $classes->addColumn('libelle', Types::STRING, ['length' => 50]);
        $classes->addColumn('effectif_max', Types::INTEGER, ['notnull' => false]);
        $classes->setPrimaryKey(['id']);
        $classes->addUniqueIndex(['annee_id', 'libelle'], 'uniq_classes_annee_libelle');
        $classes->addForeignKeyConstraint('annees_scolaires', ['annee_id'], ['id'], ['onDelete' => 'RESTRICT']);
        $classes->addForeignKeyConstraint('niveaux', ['niveau_id'], ['id'], ['onDelete' => 'RESTRICT']);

        $classeMatieres = $schema->createTable('classe_matieres');
        $classeMatieres->addOption('engine', 'InnoDB');
        $classeMatieres->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $classeMatieres->addColumn('classe_id', Types::INTEGER);
        $classeMatieres->addColumn('matiere_id', Types::INTEGER);
        $classeMatieres->addColumn('coefficient', Types::FLOAT);
        $classeMatieres->setPrimaryKey(['id']);
        $classeMatieres->addUniqueIndex(['classe_id', 'matiere_id'], 'uniq_classe_matieres_classe_matiere');
        $classeMatieres->addForeignKeyConstraint('classes', ['classe_id'], ['id'], ['onDelete' => 'CASCADE']);
        $classeMatieres->addForeignKeyConstraint('matieres', ['matiere_id'], ['id'], ['onDelete' => 'RESTRICT']);
    }

    public function down(Schema $schema): void
    {
        foreach (['classe_matieres', 'classes', 'periodes', 'matieres', 'niveaux', 'annees_scolaires'] as $table) {
            $schema->dropTable($table);
        }
    }
}
