<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Inscriptions (lot 4) : matricule, cohérence, effectif (avertissement), transfert, protection des suppressions.
 * Prérequis : clés JWT générées et base centrale de test migrée.
 */
final class InscriptionsApiTest extends WebTestCase
{
    private const SLUG = 'ins_a';
    private const PASSWORD = 'Motdepasse-Test-1';

    private KernelBrowser $client;

    /** @var array<string, string> */
    private array $tokens = [];

    private string $niveau;
    private string $annee;
    private string $classe;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->cleanup();
        self::assertSame(0, $this->runCommand('tenant:create', ['slug' => self::SLUG, 'name' => 'École test']));
        foreach (['secretariat', 'enseignant'] as $role) {
            self::assertSame(0, $this->runCommand('tenant:user:create', [
                'slug' => self::SLUG, 'email' => $role.'@ins-a.test', 'role' => $role, '--password' => self::PASSWORD,
            ]));
            $this->tokens[$role] = $this->login($role);
        }

        $this->niveau = $this->create('/api/niveaux', ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1]);
        $this->annee = $this->create('/api/annees_scolaires', ['libelle' => '2026-2027', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        $this->classe = $this->create('/api/classes', ['annee' => $this->annee, 'niveau' => $this->niveau, 'libelle' => '6e A', 'effectifMax' => 1]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testInscriptionAttribueLeMatricule(): void
    {
        // Un matricule saisi à la main occupe le numéro 1 : le générateur doit passer au suivant.
        $this->create('/api/eleves', $this->eleve('Manuel') + ['matricule' => '2026-0001']);
        $awa = $this->create('/api/eleves', $this->eleve('Awa'));
        $yao = $this->create('/api/eleves', $this->eleve('Yao'));

        $this->inscrire($awa);
        self::assertSame('2026-0002', $this->call('GET', $awa, 'secretariat')['matricule']);

        $this->inscrire($yao);
        self::assertSame('2026-0003', $this->call('GET', $yao, 'secretariat')['matricule']);
    }

    public function testCoherence(): void
    {
        $awa = $this->create('/api/eleves', $this->eleve('Awa'));
        $this->inscrire($awa);

        $this->call('POST', '/api/inscriptions', 'secretariat', $this->inscription($awa));
        self::assertResponseStatusCodeSame(422, 'même élève, même année');

        // Année suivante : « nouvelle » est refusé (déjà un parcours), « reinscription » est accepté.
        $annee2 = $this->create('/api/annees_scolaires', ['libelle' => '2027-2028', 'dateDebut' => '2027-09-01', 'dateFin' => '2028-07-15']);
        $classe2 = $this->create('/api/classes', ['annee' => $annee2, 'niveau' => $this->niveau, 'libelle' => '5e A']);
        $this->call('POST', '/api/inscriptions', 'secretariat', ['annee' => $annee2, 'classe' => $classe2, 'type' => 'nouvelle'] + $this->inscription($awa));
        self::assertResponseStatusCodeSame(422, 'nouvelle alors que l\'élève a déjà un parcours');

        $yao = $this->create('/api/eleves', $this->eleve('Yao'));
        $this->call('POST', '/api/inscriptions', 'secretariat', ['type' => 'reinscription'] + $this->inscription($yao));
        self::assertResponseStatusCodeSame(422, 'réinscription sans parcours antérieur');

        $this->call('POST', '/api/inscriptions', 'secretariat', ['annee' => $annee2] + $this->inscription($yao));
        self::assertResponseStatusCodeSame(422, 'classe d\'une autre année');

        $this->call('POST', '/api/inscriptions', 'secretariat', ['annee' => $annee2, 'classe' => $classe2, 'type' => 'reinscription'] + $this->inscription($awa));
        self::assertResponseStatusCodeSame(201);

        $this->call('POST', '/api/inscriptions', 'secretariat', ['statut' => 'terminee'] + $this->inscription($yao));
        self::assertResponseStatusCodeSame(422, 'statut terminee réservé à la clôture');
    }

    public function testEffectifDepasseEstUnSimpleAvertissement(): void
    {
        $premier = $this->inscrire($this->create('/api/eleves', $this->eleve('Awa')));
        self::assertSame([], $premier['avertissements']);

        $second = $this->inscrire($this->create('/api/eleves', $this->eleve('Yao')));
        self::assertCount(1, $second['avertissements']);
        self::assertStringContainsString('Effectif maximum dépassé', $second['avertissements'][0]);

        $data = $this->call('GET', '/api/inscriptions?classe='.$this->classe, 'enseignant');
        self::assertCount(2, $data['hydra:member'] ?? $data['member']);
    }

    public function testTransfertSortantMetAJourLeStatutDeLEleve(): void
    {
        $awa = $this->create('/api/eleves', $this->eleve('Awa'));
        $inscription = $this->inscrire($awa)['@id'];

        $this->call('PATCH', $inscription, 'secretariat', ['statut' => 'transferee']);
        self::assertResponseStatusCodeSame(422, 'date de sortie manquante');

        $this->call('PATCH', $inscription, 'secretariat', ['statut' => 'transferee', 'dateSortie' => '2027-01-10', 'motifSortie' => 'Déménagement']);
        self::assertResponseIsSuccessful();
        self::assertSame('transfere', $this->call('GET', $awa, 'secretariat')['statut']);

        $this->call('PATCH', $inscription, 'secretariat', ['statut' => 'en_cours']);
        self::assertResponseIsSuccessful();
        self::assertSame('actif', $this->call('GET', $awa, 'secretariat')['statut']);
    }

    public function testSuppressionsProtegeesEtDroits(): void
    {
        $awa = $this->create('/api/eleves', $this->eleve('Awa'));
        $inscription = $this->inscrire($awa)['@id'];

        $this->call('DELETE', $awa, 'secretariat');
        self::assertResponseStatusCodeSame(409, 'élève inscrit');
        $this->call('DELETE', $this->classe, 'secretariat');
        self::assertResponseStatusCodeSame(409, 'classe avec inscriptions');

        $this->call('POST', '/api/inscriptions', 'enseignant', $this->inscription($awa));
        self::assertResponseStatusCodeSame(403);
        $this->call('GET', '/api/inscriptions', 'enseignant');
        self::assertResponseIsSuccessful();

        $this->call('DELETE', $inscription, 'secretariat');
        self::assertResponseStatusCodeSame(204);
        $this->call('DELETE', $awa, 'secretariat');
        self::assertResponseStatusCodeSame(204);
    }

    // ---------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function eleve(string $prenoms): array
    {
        return ['nom' => 'Koffi', 'prenoms' => $prenoms, 'sexe' => 'F', 'dateNaissance' => '2012-05-14'];
    }

    /** @return array<string, mixed> */
    private function inscription(string $eleve): array
    {
        return ['eleve' => $eleve, 'annee' => $this->annee, 'classe' => $this->classe, 'type' => 'nouvelle'];
    }

    /** Inscrit l'élève (attend un 201) et renvoie l'inscription. */
    private function inscrire(string $eleve): array
    {
        $data = $this->call('POST', '/api/inscriptions', 'secretariat', $this->inscription($eleve));
        self::assertResponseStatusCodeSame(201, json_encode($data, \JSON_UNESCAPED_UNICODE));

        return $data;
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

    private function login(string $role): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['email' => $role.'@ins-a.test', 'password' => self::PASSWORD], ['HTTP_X_TENANT' => self::SLUG]);
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
