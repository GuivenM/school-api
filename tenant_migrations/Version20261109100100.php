<?php

declare(strict_types=1);

namespace TenantMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Personnes : enseignants, élèves, tuteurs, liens élève/tuteur ; titulaire de classe et enseignant de matière. */
final class Version20261109100100 extends AbstractMigration
{
    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Tenant : enseignants, élèves, tuteurs, eleve_tuteurs ; classes.titulaire_id, classe_matieres.enseignant_id';
    }

    public function up(Schema $schema): void
    {
        $enseignants = $schema->createTable('enseignants');
        $enseignants->addOption('engine', 'InnoDB');
        $enseignants->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $enseignants->addColumn('nom', Types::STRING, ['length' => 100]);
        $enseignants->addColumn('prenoms', Types::STRING, ['length' => 150]);
        $enseignants->addColumn('telephone', Types::STRING, ['length' => 30, 'notnull' => false]);
        $enseignants->addColumn('email', Types::STRING, ['length' => 180, 'notnull' => false]);
        $enseignants->addColumn('actif', Types::BOOLEAN);
        $enseignants->setPrimaryKey(['id']);

        $eleves = $schema->createTable('eleves');
        $eleves->addOption('engine', 'InnoDB');
        $eleves->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $eleves->addColumn('matricule', Types::STRING, ['length' => 30, 'notnull' => false]);
        $eleves->addColumn('nom', Types::STRING, ['length' => 100]);
        $eleves->addColumn('prenoms', Types::STRING, ['length' => 150]);
        $eleves->addColumn('sexe', Types::STRING, ['length' => 1]);
        $eleves->addColumn('date_naissance', Types::DATE_IMMUTABLE);
        $eleves->addColumn('lieu_naissance', Types::STRING, ['length' => 150, 'notnull' => false]);
        $eleves->addColumn('adresse', Types::STRING, ['length' => 255, 'notnull' => false]);
        $eleves->addColumn('statut', Types::STRING, ['length' => 20]);
        $eleves->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $eleves->setPrimaryKey(['id']);
        $eleves->addUniqueIndex(['matricule'], 'uniq_eleves_matricule');

        $tuteurs = $schema->createTable('tuteurs');
        $tuteurs->addOption('engine', 'InnoDB');
        $tuteurs->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $tuteurs->addColumn('nom', Types::STRING, ['length' => 100]);
        $tuteurs->addColumn('prenoms', Types::STRING, ['length' => 150]);
        $tuteurs->addColumn('telephone', Types::STRING, ['length' => 30]);
        $tuteurs->addColumn('telephone2', Types::STRING, ['length' => 30, 'notnull' => false]);
        $tuteurs->addColumn('email', Types::STRING, ['length' => 180, 'notnull' => false]);
        $tuteurs->addColumn('profession', Types::STRING, ['length' => 150, 'notnull' => false]);
        $tuteurs->addColumn('adresse', Types::STRING, ['length' => 255, 'notnull' => false]);
        $tuteurs->setPrimaryKey(['id']);

        $liens = $schema->createTable('eleve_tuteurs');
        $liens->addOption('engine', 'InnoDB');
        $liens->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $liens->addColumn('eleve_id', Types::INTEGER);
        $liens->addColumn('tuteur_id', Types::INTEGER);
        $liens->addColumn('lien', Types::STRING, ['length' => 20]);
        $liens->addColumn('principal', Types::BOOLEAN);
        $liens->setPrimaryKey(['id']);
        $liens->addUniqueIndex(['eleve_id', 'tuteur_id'], 'uniq_eleve_tuteurs_eleve_tuteur');
        $liens->addForeignKeyConstraint('eleves', ['eleve_id'], ['id'], ['onDelete' => 'CASCADE']);
        $liens->addForeignKeyConstraint('tuteurs', ['tuteur_id'], ['id'], ['onDelete' => 'CASCADE']);

        $classes = $schema->getTable('classes');
        $classes->addColumn('titulaire_id', Types::INTEGER, ['notnull' => false]);
        $classes->addForeignKeyConstraint('enseignants', ['titulaire_id'], ['id'], ['onDelete' => 'SET NULL']);

        $classeMatieres = $schema->getTable('classe_matieres');
        $classeMatieres->addColumn('enseignant_id', Types::INTEGER, ['notnull' => false]);
        $classeMatieres->addForeignKeyConstraint('enseignants', ['enseignant_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    /**
     * SQL explicite et ordonné : un DROP TABLE enseignants doit passer APRÈS la suppression des clés étrangères
     * de classes et classe_matieres (le diff automatique de Schema ne garantit pas cet ordre).
     */
    public function down(Schema $schema): void
    {
        foreach ([['classes', 'titulaire_id'], ['classe_matieres', 'enseignant_id']] as [$tableName, $column]) {
            $table = $schema->getTable($tableName);
            foreach ($table->getForeignKeys() as $foreignKey) {
                if (\in_array($column, $foreignKey->getLocalColumns(), true)) {
                    $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $tableName, $foreignKey->getName()));
                }
            }
            foreach ($table->getIndexes() as $index) {
                if (!$index->isPrimary() && \in_array($column, $index->getColumns(), true)) {
                    $this->addSql(sprintf('DROP INDEX %s ON %s', $index->getName(), $tableName));
                }
            }
            $this->addSql(sprintf('ALTER TABLE %s DROP %s', $tableName, $column));
        }

        foreach (['eleve_tuteurs', 'tuteurs', 'eleves', 'enseignants'] as $table) {
            $this->addSql('DROP TABLE '.$table);
        }
    }
}
