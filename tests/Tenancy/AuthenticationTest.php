<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Entity\SuperAdmin;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Prérequis : clés JWT générées (php bin/console lexik:jwt:generate-keypair) et base centrale de test migrée.
 */
final class AuthenticationTest extends WebTestCase
{
    private const A = 'auth_a';
    private const B = 'auth_b';
    private const PASSWORD = 'Motdepasse-Test-1';
    private const ADMIN_EMAIL = 'admin@test.local';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->cleanup();
        foreach ([self::A, self::B] as $slug) {
            self::assertSame(0, $this->runCommand('tenant:create', ['slug' => $slug, 'name' => 'École '.$slug]));
            self::assertSame(0, $this->runCommand('tenant:user:create', [
                'slug' => $slug, 'email' => 'directeur@'.str_replace('_', '-', $slug).'.test', 'role' => 'directeur', '--password' => self::PASSWORD,
            ]));
        }
        self::assertSame(0, $this->runCommand('admin:create', ['email' => self::ADMIN_EMAIL, '--password' => self::PASSWORD]));
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testLoginThenMe(): void
    {
        $token = $this->tenantLogin(self::A, 'directeur@auth-a.test');

        $me = $this->getJson('/api/me', $token);
        self::assertResponseIsSuccessful();
        self::assertSame(self::A, $me['tenant']);
        self::assertSame('directeur@auth-a.test', $me['email']);
        self::assertContains('ROLE_DIRECTEUR', $me['roles']);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->client->jsonRequest('POST', '/api/login', ['email' => 'directeur@auth-a.test', 'password' => 'mauvais'], ['HTTP_X_TENANT' => self::A]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginRequiresTenantHeader(): void
    {
        $this->client->jsonRequest('POST', '/api/login', ['email' => 'directeur@auth-a.test', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testUserOfAnotherTenantCannotLogin(): void
    {
        $this->client->jsonRequest('POST', '/api/login', ['email' => 'directeur@auth-b.test', 'password' => self::PASSWORD], ['HTTP_X_TENANT' => self::A]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testMeWithoutTokenIsRejected(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTenantComesFromTokenNotFromHeader(): void
    {
        $token = $this->tenantLogin(self::A, 'directeur@auth-a.test');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_X_TENANT' => self::B]);

        self::assertResponseIsSuccessful();
        self::assertSame(self::A, json_decode((string) $this->client->getResponse()->getContent(), true)['tenant']);
    }

    public function testAdminTokenAndTenantTokenAreNotInterchangeable(): void
    {
        $this->client->jsonRequest('POST', '/admin/login', ['email' => self::ADMIN_EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        $adminToken = json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
        $tenantToken = $this->tenantLogin(self::A, 'directeur@auth-a.test');

        $tenants = $this->getJson('/admin/tenants', $adminToken);
        self::assertResponseIsSuccessful();
        self::assertContains(self::A, array_column($tenants, 'slug'));

        $this->getJson('/api/me', $adminToken);
        self::assertResponseStatusCodeSame(401);

        $this->getJson('/admin/tenants', $tenantToken);
        self::assertResponseStatusCodeSame(401);
    }

    private function tenantLogin(string $slug, string $email): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['email' => $email, 'password' => self::PASSWORD], ['HTTP_X_TENANT' => $slug]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }

    private function getJson(string $uri, string $token): array
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function cleanup(): void
    {
        foreach ([self::A, self::B] as $slug) {
            $this->runCommand('tenant:delete', ['slug' => $slug, '--force' => true]);
        }

        $em = static::getContainer()->get('doctrine')->getManager('default');
        $admin = $em->getRepository(SuperAdmin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        if (null !== $admin) {
            $em->remove($admin);
            $em->flush();
        }
    }

    private function runCommand(string $name, array $input): int
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        return (new CommandTester($application->find($name)))->execute($input);
    }
}
