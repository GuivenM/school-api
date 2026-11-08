<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tenancy\Exception\NoActiveTenantException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Crée 2 vraies écoles (bases MySQL) et vérifie qu'elles sont étanches.
 * Prérequis : base centrale de test migrée (voir docs/multi-tenant.md).
 */
final class TenantIsolationTest extends WebTestCase
{
    // Slugs volontairement proches : "iso_a" et "isoxa" ne diffèrent que par un "_".
    // Si le "_" n'était pas échappé dans le GRANT, chaque utilisateur accéderait à l'autre base.
    private const TENANT_A = 'iso_a';
    private const TENANT_B = 'isoxa';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->deleteTenants();
        foreach ([self::TENANT_A, self::TENANT_B] as $slug) {
            self::assertSame(0, $this->runCommand('tenant:create', ['slug' => $slug, 'name' => 'École '.$slug]), 'tenant:create '.$slug);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function testEachTenantSeesOnlyItsOwnData(): void
    {
        $a = $this->ping(self::TENANT_A);
        self::assertSame(self::TENANT_A, $a['tenant']);
        self::assertSame($this->dbName(self::TENANT_A), $a['database']);
        self::assertSame('École iso_a', $a['nom_ecole']);

        $this->tenantConnection()->executeStatement('REPLACE INTO parametres (cle, valeur) VALUES (?, ?)', ['nom_ecole', 'MODIFIE A']);

        $b = $this->ping(self::TENANT_B);
        self::assertSame($this->dbName(self::TENANT_B), $b['database']);
        self::assertSame('École isoxa', $b['nom_ecole'], 'B ne doit pas voir la modification faite dans A');

        self::assertSame('MODIFIE A', $this->ping(self::TENANT_A)['nom_ecole']);
    }

    public function testTenantUserCannotReadAnotherTenantDatabase(): void
    {
        $this->ping(self::TENANT_A);
        $this->assertCannotRead($this->dbName(self::TENANT_B));

        $this->ping(self::TENANT_B);
        $this->assertCannotRead($this->dbName(self::TENANT_A));
    }

    public function testUnknownTenantIsRejected(): void
    {
        $this->client->request('GET', '/_tenant/ping', [], [], ['HTTP_X_TENANT' => 'ecole_inexistante']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testPingRequiresTenantHeader(): void
    {
        $this->client->request('GET', '/_tenant/ping');

        self::assertResponseStatusCodeSame(400);
    }

    public function testTenantConnectionFailsClosedWithoutActiveTenant(): void
    {
        $this->client->request('GET', '/_tenant/ping');

        $this->expectException(NoActiveTenantException::class);
        $this->tenantConnection()->executeQuery('SELECT 1');
    }

    private function assertCannotRead(string $otherDatabase): void
    {
        try {
            $this->tenantConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM `%s`.parametres', $otherDatabase));
        } catch (DbalException) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail(sprintf('L\'utilisateur de l\'école active a pu lire la base %s.', $otherDatabase));
    }

    /** @return array{tenant: string, database: string, nom_ecole: ?string} */
    private function ping(string $slug): array
    {
        $this->client->request('GET', '/_tenant/ping', [], [], ['HTTP_X_TENANT' => $slug]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function tenantConnection(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection('tenant');
    }

    private function dbName(string $slug): string
    {
        return static::getContainer()->getParameter('app.tenant.db_prefix').$slug;
    }

    private function deleteTenants(): void
    {
        foreach ([self::TENANT_A, self::TENANT_B] as $slug) {
            $this->runCommand('tenant:delete', ['slug' => $slug, '--force' => true]);
        }
    }

    private function runCommand(string $name, array $input): int
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        return (new CommandTester($application->find($name)))->execute($input);
    }
}
