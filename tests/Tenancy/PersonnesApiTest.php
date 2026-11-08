<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Élèves, tuteurs, enseignants (lot 3A) via l'API : confidentialité par rôle, validations, liens, suppressions.
 * Prérequis : clés JWT générées et base centrale de test migrée.
 */
final class PersonnesApiTest extends WebTestCase
{
    private const SLUG = 'pers_a';
    private const PASSWORD = 'Motdepasse-Test-1';
    private const ROLES = ['directeur', 'secretariat', 'enseignant', 'tuteur', 'eleve'];

    private KernelBrowser $client;

    /** @var array<string, string> jeton par rôle */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->cleanup();
        self::assertSame(0, $this->runCommand('tenant:create', ['slug' => self::SLUG, 'name' => 'École test']));
        foreach (self::ROLES as $role) {
            self::assertSame(0, $this->runCommand('tenant:user:create', [
                'slug' => self::SLUG, 'email' => $role.'@pers-a.test', 'role' => $role, '--password' => self::PASSWORD,
            ]));
            $this->tokens[$role] = $this->login($role);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testSeulLePersonnelPeutLireLesDonneesPersonnelles(): void
    {
        $this->call('GET', '/api/eleves', 'tuteur');
        self::assertResponseStatusCodeSame(403);

        $this->call('GET', '/api/tuteurs', 'eleve');
        self::assertResponseStatusCodeSame(403);

        $this->call('GET', '/api/enseignants', 'eleve');
        self::assertResponseStatusCodeSame(403);

        $this->call('GET', '/api/eleves', 'enseignant');
        self::assertResponseIsSuccessful();

        $this->call('POST', '/api/eleves', 'enseignant', $this->eleve());
        self::assertResponseStatusCodeSame(403);

        $this->call('POST', '/api/eleves', 'secretariat', $this->eleve());
        self::assertResponseStatusCodeSame(201);

        // Le référentiel reste lisible par tous les comptes connectés.
        $this->call('GET', '/api/niveaux', 'tuteur');
        self::assertResponseIsSuccessful();
    }

    public function testCreationEtValidationsEleve(): void
    {
        $data = $this->call('POST', '/api/eleves', 'secretariat', $this->eleve());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('actif', $data['statut']);
        self::assertNull($data['matricule']);

        $this->call('POST', '/api/eleves', 'secretariat', ['dateNaissance' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d')] + $this->eleve('Autre'));
        self::assertResponseStatusCodeSame(422, 'date de naissance dans le futur');

        $this->call('POST', '/api/eleves', 'secretariat', $this->eleve());
        self::assertResponseStatusCodeSame(422, 'même identité (nom, prénoms, date de naissance)');

        $this->call('POST', '/api/eleves', 'secretariat', ['matricule' => 'M-001'] + $this->eleve('Premier'));
        self::assertResponseStatusCodeSame(201);
        $this->call('POST', '/api/eleves', 'secretariat', ['matricule' => 'M-001'] + $this->eleve('Second'));
        self::assertResponseStatusCodeSame(422, 'matricule en double');

        $sansSexe = $this->eleve('Troisieme');
        unset($sansSexe['sexe']);
        $this->call('POST', '/api/eleves', 'secretariat', $sansSexe);
        self::assertResponseStatusCodeSame(422, 'sexe obligatoire');
    }

    public function testRechercheParNom(): void
    {
        $this->create('/api/eleves', $this->eleve('Awa', 'Koffi'));
        $this->create('/api/eleves', $this->eleve('Yao', 'Mensah'));

        $data = $this->call('GET', '/api/eleves?nom=koff', 'enseignant');
        self::assertCount(1, $this->members($data));
        self::assertSame('Koffi', $this->members($data)[0]['nom']);
    }

    public function testLiensEleveTuteur(): void
    {
        $eleve = $this->create('/api/eleves', $this->eleve());
        $pere = $this->create('/api/tuteurs', ['nom' => 'Koffi', 'prenoms' => 'Jean', 'telephone' => '+229 97 00 00 00']);
        $mere = $this->create('/api/tuteurs', ['nom' => 'Koffi', 'prenoms' => 'Marie', 'telephone' => '+229 96 00 00 00', 'email' => 'marie@exemple.test']);

        $this->call('POST', '/api/tuteurs', 'secretariat', ['nom' => 'X', 'prenoms' => 'Y', 'telephone' => 'abc']);
        self::assertResponseStatusCodeSame(422, 'téléphone invalide');

        $this->create('/api/eleve_tuteurs', ['eleve' => $eleve, 'tuteur' => $pere, 'lien' => 'pere', 'principal' => true]);

        $this->call('POST', '/api/eleve_tuteurs', 'secretariat', ['eleve' => $eleve, 'tuteur' => $mere, 'lien' => 'mere', 'principal' => true]);
        self::assertResponseStatusCodeSame(422, 'deux tuteurs principaux');

        $this->create('/api/eleve_tuteurs', ['eleve' => $eleve, 'tuteur' => $mere, 'lien' => 'mere', 'principal' => false]);

        $this->call('POST', '/api/eleve_tuteurs', 'secretariat', ['eleve' => $eleve, 'tuteur' => $mere, 'lien' => 'mere']);
        self::assertResponseStatusCodeSame(422, 'même lien en double');

        $data = $this->call('GET', '/api/eleve_tuteurs?eleve='.$eleve, 'enseignant');
        self::assertCount(2, $this->members($data));
    }

    public function testSuppressionEleveSupprimeSesLiens(): void
    {
        $eleve = $this->create('/api/eleves', $this->eleve());
        $tuteur = $this->create('/api/tuteurs', ['nom' => 'Koffi', 'prenoms' => 'Jean', 'telephone' => '+229 97 00 00 00']);
        $this->create('/api/eleve_tuteurs', ['eleve' => $eleve, 'tuteur' => $tuteur, 'lien' => 'pere']);

        $this->call('DELETE', $eleve, 'secretariat');
        self::assertResponseStatusCodeSame(204);

        $data = $this->call('GET', '/api/eleve_tuteurs?tuteur='.$tuteur, 'enseignant');
        self::assertCount(0, $this->members($data));
    }

    public function testTitulaireEtEnseignantDeMatiere(): void
    {
        $enseignant = $this->create('/api/enseignants', ['nom' => 'Kone', 'prenoms' => 'Awa', 'email' => 'awa@exemple.test']);
        $niveau = $this->create('/api/niveaux', ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1]);
        $annee = $this->create('/api/annees_scolaires', ['libelle' => '2026-2027', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        $classe = $this->create('/api/classes', ['annee' => $annee, 'niveau' => $niveau, 'libelle' => '6e A', 'titulaire' => $enseignant]);
        $matiere = $this->create('/api/matieres', ['code' => 'MATH', 'libelle' => 'Mathématiques']);
        $this->create('/api/classe_matieres', ['classe' => $classe, 'matiere' => $matiere, 'coefficient' => 2, 'enseignant' => $enseignant]);

        $data = $this->call('GET', '/api/classes?titulaire='.$enseignant, 'enseignant');
        self::assertCount(1, $this->members($data));

        $data = $this->call('GET', '/api/classe_matieres?enseignant='.$enseignant, 'enseignant');
        self::assertCount(1, $this->members($data));

        // Supprimer l'enseignant ne supprime pas la classe : le titulaire redevient vide.
        $this->call('DELETE', $enseignant, 'secretariat');
        self::assertResponseStatusCodeSame(204);

        $data = $this->call('GET', $classe, 'enseignant');
        self::assertResponseIsSuccessful();
        self::assertNull($data['titulaire']);
    }

    // ---------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function eleve(string $prenoms = 'Awa', string $nom = 'Koffi'): array
    {
        return ['nom' => $nom, 'prenoms' => $prenoms, 'sexe' => 'F', 'dateNaissance' => '2012-05-14', 'lieuNaissance' => 'Cotonou'];
    }

    /** Crée une ressource en tant que secrétariat (attend un 201) et renvoie son IRI. */
    private function create(string $uri, array $body): string
    {
        $data = $this->call('POST', $uri, 'secretariat', $body);
        self::assertResponseStatusCodeSame(201, sprintf('POST %s : %s', $uri, json_encode($data, \JSON_UNESCAPED_UNICODE)));

        return $data['@id'];
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $uri, string $role, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->tokens[$role],
        ], null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function members(array $collection): array
    {
        return $collection['hydra:member'] ?? $collection['member'] ?? [];
    }

    private function login(string $role): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['email' => $role.'@pers-a.test', 'password' => self::PASSWORD], ['HTTP_X_TENANT' => self::SLUG]);
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
