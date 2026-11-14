<?php

declare(strict_types=1);

namespace TenantMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Bilan annuel et décision de fin d'année portés par l'inscription. */
final class Version20261112100100 extends AbstractMigration
{
    private const COLONNES = ['moyenne_annuelle', 'rang_annuel', 'effectif_annuel', 'decision_auto', 'decision', 'decision_motif', 'decision_par', 'decision_le'];

    /** MySQL valide chaque DDL implicitement : une transaction autour ne sert à rien et fait échouer le commit. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Tenant : inscriptions.moyenne_annuelle, rang, effectif, décisions de fin d\'année';
    }

    public function up(Schema $schema): void
    {
        $inscriptions = $schema->getTable('inscriptions');
        $inscriptions->addColumn('moyenne_annuelle', Types::FLOAT, ['notnull' => false]);
        $inscriptions->addColumn('rang_annuel', Types::SMALLINT, ['notnull' => false]);
        $inscriptions->addColumn('effectif_annuel', Types::SMALLINT, ['notnull' => false]);
        $inscriptions->addColumn('decision_auto', Types::STRING, ['length' => 20, 'notnull' => false]);
        $inscriptions->addColumn('decision', Types::STRING, ['length' => 20, 'notnull' => false]);
        $inscriptions->addColumn('decision_motif', Types::STRING, ['length' => 255, 'notnull' => false]);
        $inscriptions->addColumn('decision_par', Types::STRING, ['length' => 180, 'notnull' => false]);
        $inscriptions->addColumn('decision_le', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $inscriptions = $schema->getTable('inscriptions');
        foreach (self::COLONNES as $colonne) {
            $inscriptions->dropColumn($colonne);
        }
    }
}
