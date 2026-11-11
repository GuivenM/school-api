<?php

declare(strict_types=1);

namespace TenantMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Lien d'un compte avec sa personne : utilisateurs.enseignant_id / tuteur_id / eleve_id (uniques). */
final class Version20261109200100 extends AbstractMigration
{
    private const LIENS = [
        'enseignant_id' => ['enseignants', 'uniq_utilisateurs_enseignant'],
        'tuteur_id' => ['tuteurs', 'uniq_utilisateurs_tuteur'],
        'eleve_id' => ['eleves', 'uniq_utilisateurs_eleve'],
    ];

    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Tenant : utilisateurs liés à un enseignant, un tuteur ou un élève';
    }

    public function up(Schema $schema): void
    {
        $utilisateurs = $schema->getTable('utilisateurs');
        foreach (self::LIENS as $colonne => [$table, $index]) {
            $utilisateurs->addColumn($colonne, Types::INTEGER, ['notnull' => false]);
            $utilisateurs->addUniqueIndex([$colonne], $index);
            $utilisateurs->addForeignKeyConstraint($table, [$colonne], ['id'], ['onDelete' => 'SET NULL']);
        }
    }

    /** SQL explicite : les clés étrangères doivent être supprimées avant leur index unique, puis la colonne. */
    public function down(Schema $schema): void
    {
        $utilisateurs = $schema->getTable('utilisateurs');
        foreach (array_keys(self::LIENS) as $colonne) {
            foreach ($utilisateurs->getForeignKeys() as $foreignKey) {
                if (\in_array($colonne, $foreignKey->getLocalColumns(), true)) {
                    $this->addSql(sprintf('ALTER TABLE utilisateurs DROP FOREIGN KEY %s', $foreignKey->getName()));
                }
            }
            foreach ($utilisateurs->getIndexes() as $index) {
                if (!$index->isPrimary() && \in_array($colonne, $index->getColumns(), true)) {
                    $this->addSql(sprintf('DROP INDEX %s ON utilisateurs', $index->getName()));
                }
            }
            $this->addSql(sprintf('ALTER TABLE utilisateurs DROP %s', $colonne));
        }
    }
}
