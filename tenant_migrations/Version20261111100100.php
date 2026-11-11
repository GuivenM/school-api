<?php

declare(strict_types=1);

namespace TenantMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Évaluations, notes et résultats figés (bulletins). */
final class Version20261111100100 extends AbstractMigration
{
    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Tenant : evaluations, notes, resultats_periode, resultats_matiere';
    }

    public function up(Schema $schema): void
    {
        $evaluations = $schema->createTable('evaluations');
        $evaluations->addOption('engine', 'InnoDB');
        $evaluations->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $evaluations->addColumn('classe_matiere_id', Types::INTEGER);
        $evaluations->addColumn('periode_id', Types::INTEGER);
        $evaluations->addColumn('type', Types::STRING, ['length' => 20]);
        $evaluations->addColumn('libelle', Types::STRING, ['length' => 100]);
        $evaluations->addColumn('date', Types::DATE_IMMUTABLE);
        $evaluations->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $evaluations->setPrimaryKey(['id']);
        $evaluations->addForeignKeyConstraint('classe_matieres', ['classe_matiere_id'], ['id'], ['onDelete' => 'RESTRICT']);
        $evaluations->addForeignKeyConstraint('periodes', ['periode_id'], ['id'], ['onDelete' => 'RESTRICT']);

        $notes = $schema->createTable('notes');
        $notes->addOption('engine', 'InnoDB');
        $notes->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $notes->addColumn('evaluation_id', Types::INTEGER);
        $notes->addColumn('inscription_id', Types::INTEGER);
        $notes->addColumn('valeur', Types::FLOAT, ['notnull' => false]);
        $notes->addColumn('statut', Types::STRING, ['length' => 20]);
        $notes->setPrimaryKey(['id']);
        $notes->addUniqueIndex(['evaluation_id', 'inscription_id'], 'uniq_notes_evaluation_inscription');
        $notes->addForeignKeyConstraint('evaluations', ['evaluation_id'], ['id'], ['onDelete' => 'CASCADE']);
        $notes->addForeignKeyConstraint('inscriptions', ['inscription_id'], ['id'], ['onDelete' => 'RESTRICT']);

        $resultatsPeriode = $schema->createTable('resultats_periode');
        $resultatsPeriode->addOption('engine', 'InnoDB');
        $resultatsPeriode->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $resultatsPeriode->addColumn('inscription_id', Types::INTEGER);
        $resultatsPeriode->addColumn('periode_id', Types::INTEGER);
        $resultatsPeriode->addColumn('moyenne', Types::FLOAT, ['notnull' => false]);
        $resultatsPeriode->addColumn('rang', Types::SMALLINT, ['notnull' => false]);
        $resultatsPeriode->addColumn('effectif', Types::SMALLINT);
        $resultatsPeriode->setPrimaryKey(['id']);
        $resultatsPeriode->addUniqueIndex(['inscription_id', 'periode_id'], 'uniq_resultats_periode_inscription_periode');
        $resultatsPeriode->addForeignKeyConstraint('inscriptions', ['inscription_id'], ['id'], ['onDelete' => 'RESTRICT']);
        $resultatsPeriode->addForeignKeyConstraint('periodes', ['periode_id'], ['id'], ['onDelete' => 'CASCADE']);

        $resultatsMatiere = $schema->createTable('resultats_matiere');
        $resultatsMatiere->addOption('engine', 'InnoDB');
        $resultatsMatiere->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $resultatsMatiere->addColumn('resultat_periode_id', Types::INTEGER);
        $resultatsMatiere->addColumn('matiere_id', Types::INTEGER);
        $resultatsMatiere->addColumn('matiere_libelle', Types::STRING, ['length' => 100]);
        $resultatsMatiere->addColumn('coefficient', Types::FLOAT);
        $resultatsMatiere->addColumn('moyenne', Types::FLOAT, ['notnull' => false]);
        $resultatsMatiere->addColumn('rang', Types::SMALLINT, ['notnull' => false]);
        $resultatsMatiere->setPrimaryKey(['id']);
        $resultatsMatiere->addUniqueIndex(['resultat_periode_id', 'matiere_id'], 'uniq_resultats_matiere_resultat_matiere');
        $resultatsMatiere->addForeignKeyConstraint('resultats_periode', ['resultat_periode_id'], ['id'], ['onDelete' => 'CASCADE']);
        $resultatsMatiere->addForeignKeyConstraint('matieres', ['matiere_id'], ['id'], ['onDelete' => 'RESTRICT']);
    }

    public function down(Schema $schema): void
    {
        foreach (['resultats_matiere', 'resultats_periode', 'notes', 'evaluations'] as $table) {
            $schema->dropTable($table);
        }
    }
}
