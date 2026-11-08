<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Entity\Tenant;
use App\Entity\TenantStatus;
use App\Tenant\Entity\Parametre;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Création / suppression d'une école : base MySQL dédiée + utilisateur dédié + migrations + données de départ.
 */
final class TenantProvisioner
{
    private const SLUG_PATTERN = '/^[a-z][a-z0-9_]{2,27}$/';

    public function __construct(
        private readonly Connection $landlordConnection,
        private readonly EntityManagerInterface $landlordEntityManager,
        private readonly EntityManagerInterface $tenantEntityManager,
        private readonly TenantCipher $cipher,
        private readonly TenantSwitcher $switcher,
        private readonly TenantMigrator $migrator,
        private readonly string $dbPrefix,
        private readonly string $userHost,
    ) {
    }

    public function create(string $slug, string $name): Tenant
    {
        if (1 !== preg_match(self::SLUG_PATTERN, $slug)) {
            throw new \InvalidArgumentException('Slug invalide : 3 à 28 caractères, minuscules, chiffres et "_", en commençant par une lettre.');
        }
        if (null !== $this->landlordEntityManager->getRepository(Tenant::class)->findOneBy(['slug' => $slug])) {
            throw new \RuntimeException(sprintf('L\'école « %s » existe déjà.', $slug));
        }

        $dbName = $this->dbPrefix.$slug;
        $dbUser = 'u_'.$slug;
        $password = bin2hex(random_bytes(24));

        $tenant = new Tenant($slug, $name, $dbName, $dbUser, $this->cipher->encrypt($password));
        $this->landlordEntityManager->persist($tenant);
        $this->landlordEntityManager->flush();

        try {
            $this->createDatabaseAndUser($dbName, $dbUser, $password);
            $this->switcher->switchTo($tenant);
            $this->migrator->migrate();
            $this->seed($name);

            $tenant->activate();
            $this->landlordEntityManager->flush();
        } catch (\Throwable $e) {
            $this->switcher->clear();
            try {
                $this->dropDatabaseAndUser($dbName, $dbUser);
            } catch (\Throwable) {
                // nettoyage au mieux : on garde l'erreur d'origine
            }
            $this->landlordEntityManager->remove($tenant);
            $this->landlordEntityManager->flush();

            throw $e;
        } finally {
            $this->switcher->clear();
        }

        return $tenant;
    }

    public function delete(string $slug): void
    {
        $tenant = $this->landlordEntityManager->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);
        if (null === $tenant) {
            throw new \RuntimeException(sprintf('École « %s » introuvable.', $slug));
        }

        $this->switcher->clear();
        $this->dropDatabaseAndUser($tenant->getDbName(), $tenant->getDbUser());

        $this->landlordEntityManager->remove($tenant);
        $this->landlordEntityManager->flush();
    }

    private function seed(string $name): void
    {
        $defaults = [
            'nom_ecole' => $name,
            'seuil_admission' => '10',
            'bareme_defaut' => '20',
        ];
        foreach ($defaults as $cle => $valeur) {
            $this->tenantEntityManager->persist(new Parametre($cle, $valeur));
        }
        $this->tenantEntityManager->flush();
        $this->tenantEntityManager->clear();
    }

    private function createDatabaseAndUser(string $dbName, string $dbUser, string $password): void
    {
        $c = $this->landlordConnection;
        $user = $c->quote($dbUser);
        $host = $c->quote($this->userHost);

        $c->executeStatement(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $dbName));
        $c->executeStatement(sprintf('CREATE USER %s@%s IDENTIFIED BY %s', $user, $host, $c->quote($password)));
        // "_" est un joker dans GRANT : on l'échappe, sinon "school_ab" donnerait aussi accès à "schoolxab".
        $c->executeStatement(sprintf(
            'GRANT ALL PRIVILEGES ON `%s`.* TO %s@%s',
            str_replace('_', '\\_', $dbName),
            $user,
            $host,
        ));
    }

    private function dropDatabaseAndUser(string $dbName, string $dbUser): void
    {
        $c = $this->landlordConnection;

        $c->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $dbName));
        $c->executeStatement(sprintf('DROP USER IF EXISTS %s@%s', $c->quote($dbUser), $c->quote($this->userHost)));
    }
}
