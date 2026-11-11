<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Gestion des comptes par le directeur (lot 3B) : droits, cohérence avec les personnes, désactivation,
 * garde-fou « dernier directeur », mots de passe.
 * Prérequis : clés JWT générées et base centrale de test migrée.
 */
final class UtilisateursApiTest extends WebTestCase
{
    private const SLUG = 'usr_a';
    private const PASSWORD = 'Motdepasse-Test-1';
    private const DIRECTEUR = 'directeur@usr-a.test';

    private KernelBrowser $client;
    private string $directeur;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->cleanup();
        self::assertSame(0, $this->runCommand('tenant:create', ['slug' => self::SLUG, 'name' => 'École test']));
        foreach (['directeur', 'secretariat'] as $role) {
            self::assertSame(0, $this->runCommand('tenant:user:create', [
                'slug' => self::SLUG, 'email' => $role.'@usr-a.test', 'role' => $role, '--password' => self::PASSWORD,
            ]));
        }
        $this->directeur = (string) $this->login(self::DIRECTEUR, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testSeulLeDirecteurGereLesComptesEtLeHashNeFuitePas(): void
    {
        $secretariat = (string) $this->login('secretariat@usr-a.test', self::PASSWORD);
        $this->call('GET', '/api/utilisateurs', $secretariat);
        self::assertResponseStatusCodeSame(403);

        $this->call('GET', '/api/utilisateurs', $this->directeur);
        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsStringIgnoringCase('password', $contenu);
        self::assertStringNotContainsString('$2y$', $contenu);
        self::assertStringNotContainsString('$argon', $contenu);
    }

    public function testCreationDUnCompteEnseignantLie(): void
    {
        $enseignant = $this->create('/api/enseignants', ['nom' => 'Kone', 'prenoms' => 'Awa']);
        $data = $this->call('POST', '/api/utilisateurs', $this->directeur, [
            'email' => 'Awa@usr-a.test', 'role' => 'enseignant', 'plainPassword' => 'Motdepasse-Test-2', 'enseignant' => $enseignant, 'nom' => 'Kone',
        ]);
        self::assertResponseStatusCodeSame(201, json_encode($data, \JSON_UNESCAPED_UNICODE));
        self::assertSame('awa@usr-a.test', $data['email']);
        self::assertTrue($data['actif']);
        self::assertStringNotContainsStringIgnoringCase('password', (string) $this->client->getResponse()->getContent());

        $token = (string) $this->login('awa@usr-a.test', 'Motdepasse-Test-2');
        $me = $this->call('GET', '/api/me', $token);
        self::assertContains('ROLE_ENSEIGNANT', $me['roles']);

        $this->call('GET', '/api/eleves', $token);
        self::assertResponseIsSuccessful();
        $this->call('GET', '/api/utilisateurs', $token);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCoherenceAvecLesPersonnes(): void
    {
        $enseignant = $this->create('/api/enseignants', ['nom' => 'Kone', 'prenoms' => 'Awa']);
        $eleve = $this->create('/api/eleves', ['nom' => 'Koffi', 'prenoms' => 'Awa', 'sexe' => 'F', 'dateNaissance' => '2012-05-14']);
        $base = ['plainPassword' => 'Motdepasse-Test-2'];

        $this->call('POST', '/api/utilisateurs', $this->directeur, $base + ['email' => 'a@usr-a.test', 'role' => 'enseignant']);
        self::assertResponseStatusCodeSame(422, 'enseignant sans lien');

        $this->call('POST', '/api/utilisateurs', $this->directeur, $base + ['email' => 'b@usr-a.test', 'role' => 'tuteur', 'eleve' => $eleve]);
        self::assertResponseStatusCodeSame(422, 'tuteur lié à un élève');

        $this->call('POST', '/api/utilisateurs', $this->directeur, ['email' => 'c@usr-a.test', 'role' => 'comptable']);
        self::assertResponseStatusCodeSame(422, 'mot de passe manquant');

        $this->call('POST', '/api/utilisateurs', $this->directeur, ['email' => 'd@usr-a.test', 'role' => 'comptable', 'plainPassword' => 'court']);
        self::assertResponseStatusCodeSame(422, 'mot de passe trop court');

        $this->create('/api/utilisateurs', $base + ['email' => 'e@usr-a.test', 'role' => 'enseignant', 'enseignant' => $enseignant]);
        $this->call('POST', '/api/utilisateurs', $this->directeur, $base + ['email' => 'f@usr-a.test', 'role' => 'enseignant', 'enseignant' => $enseignant]);
        self::assertResponseStatusCodeSame(422, 'même enseignant lié deux fois');

        $this->call('POST', '/api/utilisateurs', $this->directeur, $base + ['email' => 'E@usr-a.test', 'role' => 'comptable']);
        self::assertResponseStatusCodeSame(422, 'email déjà utilisé (sans tenir compte de la casse)');
    }

    public function testDesactivationCoupeLaConnexionEtLesJetonsEnCours(): void
    {
        $iri = $this->create('/api/utilisateurs', ['email' => 'compta@usr-a.test', 'role' => 'comptable', 'plainPassword' => 'Motdepasse-Test-2']);
        $token = (string) $this->login('compta@usr-a.test', 'Motdepasse-Test-2');
        $this->call('GET', '/api/me', $token);
        self::assertResponseIsSuccessful();

        $this->call('PATCH', $iri, $this->directeur, ['actif' => false]);
        self::assertResponseIsSuccessful();

        $this->call('GET', '/api/me', $token);
        self::assertResponseStatusCodeSame(401, 'jeton déjà émis');
        self::assertNull($this->login('compta@usr-a.test', 'Motdepasse-Test-2'), 'nouvelle connexion');

        $this->call('PATCH', $iri, $this->directeur, ['actif' => true]);
        self::assertNotNull($this->login('compta@usr-a.test', 'Motdepasse-Test-2'));
    }

    public function testUneEcoleGardeToujoursUnDirecteurActif(): void
    {
        $moi = $this->iriDe(self::DIRECTEUR);

        $this->call('PATCH', $moi, $this->directeur, ['actif' => false]);
        self::assertResponseStatusCodeSame(422, 'désactiver le seul directeur');

        $this->call('PATCH', $moi, $this->directeur, ['role' => 'secretariat']);
        self::assertResponseStatusCodeSame(422, 'rétrograder le seul directeur');

        $this->create('/api/utilisateurs', ['email' => 'dir2@usr-a.test', 'role' => 'directeur', 'plainPassword' => 'Motdepasse-Test-2']);
        $this->call('PATCH', $moi, $this->directeur, ['actif' => false]);
        self::assertResponseIsSuccessful('avec un second directeur actif');
    }

    public function testReinitialisationParLeDirecteurEtChangementDeSonMotDePasse(): void
    {
        $iri = $this->create('/api/utilisateurs', ['email' => 'compta@usr-a.test', 'role' => 'comptable', 'plainPassword' => 'Motdepasse-Test-2']);

        $this->call('PATCH', $iri, $this->directeur, ['plainPassword' => 'Motdepasse-Test-3']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->login('compta@usr-a.test', 'Motdepasse-Test-2'));
        $token = (string) $this->login('compta@usr-a.test', 'Motdepasse-Test-3');

        $this->client->jsonRequest('POST', '/api/me/password', ['currentPassword' => 'mauvais', 'newPassword' => 'Motdepasse-Test-4'], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(422, 'mot de passe actuel incorrect');

        $this->client->jsonRequest('POST', '/api/me/password', ['currentPassword' => 'Motdepasse-Test-3', 'newPassword' => 'court'], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(422, 'nouveau mot de passe trop court');

        $this->client->jsonRequest('POST', '/api/me/password', ['currentPassword' => 'Motdepasse-Test-3', 'newPassword' => 'Motdepasse-Test-4'], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(204);

        self::assertNull($this->login('compta@usr-a.test', 'Motdepasse-Test-3'));
        self::assertNotNull($this->login('compta@usr-a.test', 'Motdepasse-Test-4'));
    }

    // ---------------------------------------------------------------------------------------------

    /** Crée une ressource en tant que directeur (attend un 201) et renvoie son IRI. */
    private function create(string $uri, array $body): string
    {
        $data = $this->call('POST', $uri, $this->directeur, $body);
        self::assertResponseStatusCodeSame(201, sprintf('POST %s : %s', $uri, json_encode($data, \JSON_UNESCAPED_UNICODE)));

        return $data['@id'];
    }

    private function iriDe(string $email): string
    {
        $data = $this->call('GET', '/api/utilisateurs?email='.urlencode($email), $this->directeur);
        $membres = $data['hydra:member'] ?? $data['member'] ?? [];
        self::assertCount(1, $membres);

        return $membres[0]['@id'];
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $uri, string $token, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** Renvoie le jeton, ou null si la connexion est refusée. */
    private function login(string $email, string $password): ?string
    {
        $this->client->jsonRequest('POST', '/api/login', ['email' => $email, 'password' => $password], ['HTTP_X_TENANT' => self::SLUG]);
        if (401 === $this->client->getResponse()->getStatusCode()) {
            return null;
        }
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }

    private function cleanup(): void
    {
        $this->runCommand('tenant:delete', ['slug' => self::SLUG, '--force' => true]);
    }

    private function runCommand(string $name, array $input): int
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        return (new CommandTester($application->find($name)))->execute($input);
    }
}
