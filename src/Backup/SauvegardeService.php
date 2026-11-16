<?php

declare(strict_types=1);

namespace App\Backup;

use App\Entity\Sauvegarde;
use App\Entity\Tenant;
use App\Entity\TypeSauvegarde;
use App\Tenancy\TenantCipher;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMigrator;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantSwitcher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sauvegarde, vérification, restauration et purge des bases d'école.
 * Chaîne : mysqldump → gzip → chiffrement → copie sur chaque destination (local, distant) → ligne dans la table sauvegardes.
 */
final class SauvegardeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BackupStorages $stockages,
        private readonly BackupCrypto $crypto,
        private readonly MysqlTools $mysql,
        private readonly TenantCipher $cipher,
        private readonly TenantProvisioner $provisioner,
        private readonly TenantSwitcher $switcher,
        private readonly TenantMigrator $migrator,
        private readonly TenantContext $contexte,
    ) {
    }

    public function sauvegarderEcoleCourante(TypeSauvegarde $type, ?string $note = null, ?string $anneeLibelle = null): Sauvegarde
    {
        $tenant = null === $this->contexte->getSlug() ? null : $this->entityManager->getRepository(Tenant::class)->findOneBy(['slug' => $this->contexte->getSlug()]);
        if (null === $tenant) {
            throw new \LogicException('Aucune école active.');
        }

        return $this->sauvegarder($tenant, $type, $note, $anneeLibelle);
    }

    public function sauvegarder(Tenant $tenant, TypeSauvegarde $type, ?string $note = null, ?string $anneeLibelle = null): Sauvegarde
    {
        $destinations = $this->stockages->actives();
        if ([] === $destinations) {
            throw new \LogicException('Aucune destination de sauvegarde : renseigner BACKUP_DESTINATIONS (local, remote).');
        }

        $maintenant = new \DateTimeImmutable();
        $chemin = sprintf(
            '%s/%s/%s/%s_%s_%s_%s.sql.gz.enc',
            $tenant->getSlug(), $maintenant->format('Y'), $maintenant->format('m'),
            $tenant->getSlug(), $maintenant->format('Ymd-His'), $type->value, bin2hex(random_bytes(2)),
        );

        $racine = $this->fichierTemporaire();
        $gz = $racine.'.gz';
        $chiffre = $racine.'.enc';
        $reussies = [];
        $echouees = [];
        $erreurs = [];

        try {
            $this->mysql->exporter($tenant->getDbName(), $tenant->getDbUser(), $this->cipher->decrypt($tenant->getDbPasswordEnc()), $gz);
            $this->crypto->chiffrer($gz, $chiffre);
            $sha256 = (string) hash_file('sha256', $chiffre);
            $taille = (int) filesize($chiffre);

            foreach ($destinations as $destination) {
                try {
                    $this->stockages->ecrire($destination, $chemin, $chiffre);
                    $reussies[] = $destination;
                } catch (\Throwable $e) {
                    $echouees[] = $destination;
                    $erreurs[] = $destination.' : '.$e->getMessage();
                }
            }
        } finally {
            foreach ([$racine, $gz, $chiffre] as $fichier) {
                @unlink($fichier);
            }
        }

        if ([] === $reussies) {
            throw new \RuntimeException('Sauvegarde non enregistrée sur aucune destination — '.implode(' ; ', $erreurs));
        }

        $sauvegarde = new Sauvegarde($tenant, $type, $chemin, $taille, $sha256, $reussies, $echouees, $anneeLibelle, $note);
        $this->entityManager->persist($sauvegarde);
        $this->entityManager->flush();

        return $sauvegarde;
    }

    /**
     * Contrôle chaque copie : empreinte identique à celle enregistrée ET fichier déchiffrable avec la clé actuelle.
     *
     * @return array<string, string> destination => « ok » ou message d'erreur
     */
    public function verifier(Sauvegarde $sauvegarde): array
    {
        $resultats = [];
        foreach ($sauvegarde->getDestinations() as $destination) {
            $racine = $this->fichierTemporaire();
            try {
                $this->stockages->lireVers($destination, $sauvegarde->getChemin(), $racine.'.enc');
                if (hash_file('sha256', $racine.'.enc') !== $sauvegarde->getSha256()) {
                    throw new \RuntimeException('empreinte différente (fichier altéré ou incomplet)');
                }
                $this->crypto->dechiffrer($racine.'.enc', $racine.'.gz');
                $resultats[$destination] = 'ok';
            } catch (\Throwable $e) {
                $resultats[$destination] = $e->getMessage();
            } finally {
                foreach ([$racine, $racine.'.enc', $racine.'.gz'] as $fichier) {
                    @unlink($fichier);
                }
            }
        }

        return $resultats;
    }

    /**
     * Remplace le contenu de la base de l'école par celui de la sauvegarde. Une sauvegarde « avant_restauration » de l'état actuel
     * est prise d'abord et renvoyée : en cas de mauvais choix, on peut revenir en arrière avec elle.
     */
    public function restaurer(Sauvegarde $sauvegarde): Sauvegarde
    {
        $tenant = $sauvegarde->getTenant();
        if (null === $tenant) {
            throw new \RuntimeException('L\'école de cette sauvegarde a été supprimée : restauration impossible.');
        }

        $filet = $this->sauvegarder($tenant, TypeSauvegarde::AvantRestauration, sprintf('Avant restauration de la sauvegarde n°%d', $sauvegarde->getId()));

        $racine = $this->fichierTemporaire();
        $chiffre = $racine.'.enc';
        $gz = $racine.'.gz';
        try {
            $this->telecharger($sauvegarde, $chiffre);
            $this->crypto->dechiffrer($chiffre, $gz);

            $this->provisioner->reinitialiserBase($tenant);
            $this->mysql->importer($tenant->getDbName(), $tenant->getDbUser(), $this->cipher->decrypt($tenant->getDbPasswordEnc()), $gz);

            // Une sauvegarde ancienne peut précéder des migrations plus récentes : on met le schéma à jour.
            $this->switcher->switchTo($tenant);
            $this->migrator->migrate();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Restauration échouée (%s). L\'état d\'avant est conservé dans la sauvegarde n°%d.', $e->getMessage(), $filet->getId()), 0, $e);
        } finally {
            $this->switcher->clear();
            foreach ([$racine, $chiffre, $gz] as $fichier) {
                @unlink($fichier);
            }
        }

        return $filet;
    }

    /** @return int nombre de sauvegardes supprimées (selon TypeSauvegarde::retentionJours) */
    public function purger(): int
    {
        $supprimees = 0;
        foreach ($this->entityManager->getRepository(Sauvegarde::class)->findAll() as $sauvegarde) {
            $jours = $sauvegarde->getType()->retentionJours();
            if (null === $jours || $sauvegarde->getCreatedAt() > new \DateTimeImmutable(sprintf('-%d days', $jours))) {
                continue;
            }

            $restantes = [];
            foreach ($sauvegarde->getDestinations() as $destination) {
                try {
                    $this->stockages->supprimer($destination, $sauvegarde->getChemin());
                } catch (\Throwable) {
                    $restantes[] = $destination; // on réessaiera à la prochaine purge
                }
            }

            if ([] === $restantes) {
                $this->entityManager->remove($sauvegarde);
                ++$supprimees;
            } else {
                $sauvegarde->setDestinations($restantes);
            }
        }
        $this->entityManager->flush();

        return $supprimees;
    }

    private function telecharger(Sauvegarde $sauvegarde, string $fichier): void
    {
        $erreurs = [];
        foreach ($sauvegarde->getDestinations() as $destination) {
            try {
                $this->stockages->lireVers($destination, $sauvegarde->getChemin(), $fichier);
                if (hash_file('sha256', $fichier) === $sauvegarde->getSha256()) {
                    return;
                }
                $erreurs[] = $destination.' : empreinte différente';
            } catch (\Throwable $e) {
                $erreurs[] = $destination.' : '.$e->getMessage();
            }
        }

        throw new \RuntimeException('aucune copie intacte de la sauvegarde ('.implode(' ; ', $erreurs).')');
    }

    private function fichierTemporaire(): string
    {
        $fichier = tempnam(sys_get_temp_dir(), 'sco');
        if (false === $fichier) {
            throw new \RuntimeException('Fichier temporaire impossible à créer.');
        }

        return $fichier;
    }
}
