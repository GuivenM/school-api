<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Tenant\Entity\AnneeScolaire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Matricule « AAAA-NNNN » : année de début de l'année scolaire de la première inscription + numéro d'ordre dans l'école.
 * Le compteur est incrémenté de façon atomique en base (deux inscriptions simultanées n'obtiennent jamais le même numéro).
 */
final class MatriculeGenerator
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    public function generer(AnneeScolaire $annee): string
    {
        $debut = (int) $annee->getDateDebut()?->format('Y');
        if ($debut < 1900) {
            throw new \LogicException('Année scolaire sans date de début : matricule impossible.');
        }

        $connection = $this->tenantEntityManager->getConnection();

        // Un matricule saisi à la main (reprise de données) peut déjà occuper un numéro : on passe au suivant.
        for ($essai = 0; $essai < 100; ++$essai) {
            $connection->executeStatement(
                'INSERT INTO sequences (cle, valeur) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE valeur = LAST_INSERT_ID(valeur + 1)',
                ['matricule_'.$debut],
            );
            $matricule = sprintf('%d-%04d', $debut, (int) $connection->fetchOne('SELECT LAST_INSERT_ID()'));

            if (false === $connection->fetchOne('SELECT 1 FROM eleves WHERE matricule = ?', [$matricule])) {
                return $matricule;
            }
        }

        throw new \RuntimeException('Impossible de générer un matricule libre.');
    }
}
