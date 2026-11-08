<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Référentiel (lot 2) via l'API : droits, validations, règles métier, isolation entre écoles.
 * Prérequis : clés JWT générées et base centrale de test migrée.
 */
final class ReferentielApiTest extends WebTestCase
{
    private const A = 'ref_a';
    private const B = 'ref_b';
    private const PASSWORD = 'Motdepasse-Test-1';

    private KernelBrowser $client;
    private string $directeur;
    private string $enseignant;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->cleanup();
        $this->createSchool(self::A, ['directeur', 'enseignant']);
        $this->directeur = $this->login(self::A, 'directeur');
        $this->enseignant = $this->login(self::A, 'enseignant');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testLecteurPeutLireMaisPasEcrire(): void
    {
        $this->call('GET', '/api/niveaux', $this->enseignant);
        self::assertResponseIsSuccessful();

        $this->call('POST', '/api/niveaux', $this->enseignant, ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1]);
        self::assertResponseStatusCodeSame(403);

        $this->call('POST', '/api/niveaux', $this->directeur, ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testConstructionDuReferentiel(): void
    {
        $cinq = $this->create('/api/niveaux', ['code' => '5E', 'libelle' => 'Cinquième', 'ordre' => 2]);
        $six = $this->create('/api/niveaux', ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1, 'niveauSuivant' => $cinq]);
        $maths = $this->create('/api/matieres', ['code' => 'MATH', 'libelle' => 'Mathématiques']);
        $annee = $this->create('/api/annees_scolaires', ['libelle' => '2026-2027', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        $this->create('/api/periodes', ['annee' => $annee, 'numero' => 1, 'libelle' => '1er trimestre', 'dateDebut' => '2026-09-01', 'dateFin' => '2026-12-15']);
        $classe = $this->create('/api/classes', ['annee' => $annee, 'niveau' => $six, 'libelle' => '6e A', 'effectifMax' => 40]);
        $this->create('/api/classe_matieres', ['classe' => $classe, 'matiere' => $maths, 'coefficient' => 2]);

        $data = $this->call('GET', '/api/annees_scolaires', $this->directeur);
        self::assertSame('brouillon', $this->members($data)[0]['statut']);

        $data = $this->call('GET', '/api/classes?annee='.$annee, $this->directeur);
        self::assertCount(1, $this->members($data));

        $data = $this->call('GET', '/api/classe_matieres?classe='.$classe, $this->directeur);
        self::assertEquals(2, $this->members($data)[0]['coefficient']);
    }

    public function testValidations(): void
    {
        $this->call('POST', '/api/annees_scolaires', $this->directeur, ['libelle' => '2026', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        self::assertResponseStatusCodeSame(422);

        $this->call('POST', '/api/annees_scolaires', $this->directeur, ['libelle' => '2026-2028', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        self::assertResponseStatusCodeSame(422);

        $annee = $this->create('/api/annees_scolaires', ['libelle' => '2026-2027', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        $this->call('POST', '/api/annees_scolaires', $this->directeur, ['libelle' => '2026-2027', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        self::assertResponseStatusCodeSame(422, 'doublon de libellé');

        $periode = ['annee' => $annee, 'libelle' => 'T', 'dateDebut' => '2026-09-01', 'dateFin' => '2026-12-15'];
        $this->call('POST', '/api/periodes', $this->directeur, $periode + ['numero' => 4]);
        self::assertResponseStatusCodeSame(422, 'numéro > 3');

        $this->call('POST', '/api/periodes', $this->directeur, ['numero' => 1, 'dateDebut' => '2026-08-01', 'dateFin' => '2026-12-15'] + $periode);
        self::assertResponseStatusCodeSame(422, 'début avant l\'année scolaire');

        $this->create('/api/periodes', $periode + ['numero' => 1]);
        $this->call('POST', '/api/periodes', $this->directeur, $periode + ['numero' => 1]);
        self::assertResponseStatusCodeSame(422, 'trimestre en double');

        $niveau = $this->create('/api/niveaux', ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1]);
        $matiere = $this->create('/api/matieres', ['code' => 'MATH', 'libelle' => 'Mathématiques']);
        $classe = $this->create('/api/classes', ['annee' => $annee, 'niveau' => $niveau, 'libelle' => '6e A']);
        $this->call('POST', '/api/classe_matieres', $this->directeur, ['classe' => $classe, 'matiere' => $matiere, 'coefficient' => 0]);
        self::assertResponseStatusCodeSame(422, 'coefficient nul');
    }

    public function testUneSeuleAnneeActiveEtStatutsReserves(): void
    {
        $a = $this->create('/api/annees_scolaires', ['libelle' => '2025-2026', 'dateDebut' => '2025-09-01', 'dateFin' => '2026-07-15']);
        $b = $this->create('/api/annees_scolaires', ['libelle' => '2026-2027', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);

        $this->call('PATCH', $a, $this->directeur, ['statut' => 'active']);
        self::assertResponseIsSuccessful();

        $this->call('PATCH', $b, $this->directeur, ['statut' => 'active']);
        self::assertResponseStatusCodeSame(422, 'deuxième année active');

        $this->call('PATCH', $a, $this->directeur, ['statut' => 'cloturee']);
        self::assertResponseStatusCodeSame(422, 'clôture réservée au lot 6');
    }

    public function testSuppressionBloqueeQuandElementUtilise(): void
    {
        $annee = $this->create('/api/annees_scolaires', ['libelle' => '2026-2027', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        $niveau = $this->create('/api/niveaux', ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1]);
        $this->create('/api/classes', ['annee' => $annee, 'niveau' => $niveau, 'libelle' => '6e A']);

        $this->call('DELETE', $annee, $this->directeur);
        self::assertResponseStatusCodeSame(409);

        $this->call('DELETE', $niveau, $this->directeur);
        self::assertResponseStatusCodeSame(409);
    }

    public function testChaqueEcoleAUnReferentielIndependant(): void
    {
        $this->create('/api/niveaux', ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1]);

        $this->createSchool(self::B, ['directeur']);
        $tokenB = $this->login(self::B, 'directeur');

        $data = $this->call('GET', '/api/niveaux', $tokenB);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->members($data));

        // Le même code de niveau peut exister dans deux écoles.
        $this->call('POST', '/api/niveaux', $tokenB, ['code' => '6E', 'libelle' => 'Sixième B', 'ordre' => 1]);
        self::assertResponseStatusCodeSame(201);
    }

    // ---------------------------------------------------------------------------------------------

    /** Crée une ressource (attend un 201) et renvoie son IRI. */
    private function create(string $uri, array $body): string
    {
        $data = $this->call('POST', $uri, $this->directeur, $body);
        self::assertResponseStatusCodeSame(201, sprintf('POST %s : %s', $uri, json_encode($data, \JSON_UNESCAPED_UNICODE)));

        return $data['@id'];
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

    /** @return list<array<string, mixed>> */
    private function members(array $collection): array
    {
        return $collection['hydra:member'] ?? $collection['member'] ?? [];
    }

    private function createSchool(string $slug, array $roles): void
    {
        self::assertSame(0, $this->runCommand('tenant:create', ['slug' => $slug, 'name' => 'École '.$slug]));
        foreach ($roles as $role) {
            self::assertSame(0, $this->runCommand('tenant:user:create', [
                'slug' => $slug, 'email' => $this->email($slug, $role), 'role' => $role, '--password' => self::PASSWORD,
            ]));
        }
    }

    private function email(string $slug, string $role): string
    {
        return $role.'@'.str_replace('_', '-', $slug).'.test';
    }

    private function login(string $slug, string $role): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['email' => $this->email($slug, $role), 'password' => self::PASSWORD], ['HTTP_X_TENANT' => $slug]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }

    private function cleanup(): void
    {
        foreach ([self::A, self::B] as $slug) {
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
