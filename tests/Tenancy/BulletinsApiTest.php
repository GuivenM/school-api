<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Appréciations et bulletins PDF (lot 5B). Prérequis : dompdf/dompdf installé, clés JWT générées, base centrale de test migrée.
 */
final class BulletinsApiTest extends WebTestCase
{
    private const SLUG = 'bul_a';
    private const PASSWORD = 'Motdepasse-Test-1';

    private KernelBrowser $client;

    /** @var array<string, string> */
    private array $tokens = [];

    private string $annee;
    private string $t1;
    private string $classe;
    private string $maths;
    private string $francais;
    private string $awa;
    private string $yao;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->cleanup();
        self::assertSame(0, $this->runCommand('tenant:create', ['slug' => self::SLUG, 'name' => 'École test']));
        foreach (['directeur', 'secretariat'] as $role) {
            self::assertSame(0, $this->runCommand('tenant:user:create', [
                'slug' => self::SLUG, 'email' => $role.'@bul-a.test', 'role' => $role, '--password' => self::PASSWORD,
            ]));
            $this->tokens[$role] = $this->login($role.'@bul-a.test');
        }

        // prof1 enseigne les maths ; prof2 enseigne le français et est titulaire de la classe.
        $enseignants = [];
        foreach (['prof1' => 'Kone', 'prof2' => 'Diallo'] as $compte => $nom) {
            $enseignants[$compte] = $this->create('/api/enseignants', ['nom' => $nom, 'prenoms' => 'Test']);
            $this->create('/api/utilisateurs', ['email' => $compte.'@bul-a.test', 'role' => 'enseignant', 'plainPassword' => self::PASSWORD, 'enseignant' => $enseignants[$compte]]);
            $this->tokens[$compte] = $this->login($compte.'@bul-a.test');
        }

        $niveau = $this->create('/api/niveaux', ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1]);
        $this->annee = $this->create('/api/annees_scolaires', ['libelle' => '2026-2027', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        $this->t1 = $this->create('/api/periodes', ['annee' => $this->annee, 'numero' => 1, 'libelle' => '1er trimestre', 'dateDebut' => '2026-09-01', 'dateFin' => '2026-12-15']);
        $this->create('/api/periodes', ['annee' => $this->annee, 'numero' => 2, 'libelle' => '2e trimestre', 'dateDebut' => '2027-01-05', 'dateFin' => '2027-03-31']);
        $this->classe = $this->create('/api/classes', ['annee' => $this->annee, 'niveau' => $niveau, 'libelle' => '6e A', 'titulaire' => $enseignants['prof2']]);
        $this->maths = $this->create('/api/matieres', ['code' => 'MATH', 'libelle' => 'Mathématiques']);
        $this->francais = $this->create('/api/matieres', ['code' => 'FRAN', 'libelle' => 'Français']);
        $cmMath = $this->create('/api/classe_matieres', ['classe' => $this->classe, 'matiere' => $this->maths, 'coefficient' => 2, 'enseignant' => $enseignants['prof1']]);
        $cmFran = $this->create('/api/classe_matieres', ['classe' => $this->classe, 'matiere' => $this->francais, 'coefficient' => 1, 'enseignant' => $enseignants['prof2']]);

        foreach (['awa' => 'Awa', 'yao' => 'Yao'] as $propriete => $prenoms) {
            $eleve = $this->create('/api/eleves', ['nom' => 'Koffi', 'prenoms' => $prenoms, 'sexe' => 'F', 'dateNaissance' => '2012-05-14']);
            $this->{$propriete} = $this->create('/api/inscriptions', ['eleve' => $eleve, 'annee' => $this->annee, 'classe' => $this->classe, 'type' => 'nouvelle']);
        }

        foreach ([[$cmMath, 14, 8], [$cmFran, 10, 12]] as [$cm, $noteAwa, $noteYao]) {
            $evaluation = $this->create('/api/evaluations', ['classeMatiere' => $cm, 'periode' => $this->t1, 'type' => 'devoir', 'libelle' => 'Devoir 1', 'date' => '2026-10-01']);
            $this->call('POST', $evaluation.'/notes', 'directeur', ['notes' => [['inscription' => $this->awa, 'valeur' => $noteAwa], ['inscription' => $this->yao, 'valeur' => $noteYao]]]);
            self::assertResponseIsSuccessful();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testAppreciationsEtDroitsDeRedaction(): void
    {
        $url = $this->awa.'/appreciations/'.basename($this->t1);
        $math = basename($this->maths);
        $fran = basename($this->francais);

        $data = $this->call('PUT', $url, 'prof1', ['matieres' => [$math => 'Très bon travail']]);
        self::assertResponseIsSuccessful();
        self::assertSame('Très bon travail', $data['matieres'][$math]);

        $this->call('PUT', $url, 'prof1', ['generale' => 'Pas titulaire']);
        self::assertResponseStatusCodeSame(403, 'appréciation générale : titulaire seulement');
        $this->call('PUT', $url, 'prof1', ['matieres' => [$fran => 'Pas ma matière']]);
        self::assertResponseStatusCodeSame(403, 'matière d\'un autre enseignant');

        $data = $this->call('PUT', $url, 'prof2', ['generale' => 'Bon trimestre', 'matieres' => [$fran => 'Efforts à poursuivre']]);
        self::assertResponseIsSuccessful();
        self::assertSame('Bon trimestre', $data['generale']);

        // Tout ou rien : une seule partie interdite et rien n'est écrit.
        $this->call('PUT', $url, 'prof2', ['generale' => 'Modifiée', 'matieres' => [$math => 'Interdit']]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Bon trimestre', $this->call('GET', $url, 'prof1')['generale']);

        $this->call('PUT', $url, 'prof2', ['generale' => str_repeat('x', 501)]);
        self::assertResponseStatusCodeSame(422, 'trop long');
        $this->call('PUT', $url, 'secretariat', ['matieres' => ['99999' => 'Matière inconnue']]);
        self::assertResponseStatusCodeSame(422, 'matière absente de la classe');

        $data = $this->call('PUT', $url, 'prof2', ['generale' => null]);
        self::assertNull($data['generale'], 'null supprime l\'appréciation');

        // Trimestre fermé : plus de modification.
        $this->call('POST', $this->t1.'/fermer', 'secretariat');
        self::assertResponseIsSuccessful();
        $this->call('PUT', $url, 'secretariat', ['generale' => 'Trop tard']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testLeBulletinJsonContientLesAppreciations(): void
    {
        $url = $this->awa.'/appreciations/'.basename($this->t1);
        $this->call('PUT', $url, 'secretariat', ['generale' => 'Élève sérieuse', 'matieres' => [basename($this->maths) => 'Très bon travail']]);
        self::assertResponseIsSuccessful();

        $bulletin = $this->call('GET', $this->awa.'/bulletin', 'prof1');
        self::assertResponseIsSuccessful();
        self::assertSame('École test', $bulletin['ecole']);
        self::assertSame('2026-2027', $bulletin['anneeLibelle']);
        self::assertSame('6e A', $bulletin['classeLibelle']);
        self::assertSame('2012-05-14', $bulletin['eleve']['dateNaissance']);
        self::assertSame('Élève sérieuse', $bulletin['periodes'][0]['appreciation']);
        self::assertSame('Très bon travail', $bulletin['periodes'][0]['matieres'][0]['appreciation']);
        self::assertNull($bulletin['periodes'][0]['matieres'][1]['appreciation']);
    }

    public function testBulletinsPdf(): void
    {
        $this->pdf($this->awa.'/bulletin.pdf?periode='.basename($this->t1), 'prof1');
        $this->pdf($this->awa.'/bulletin.pdf', 'secretariat');

        $classe = $this->pdf($this->classe.'/bulletins.pdf?periode='.basename($this->t1), 'secretariat');
        self::assertGreaterThan(1000, \strlen($classe));

        // Trimestre d'une autre année : refusé.
        $autreAnnee = $this->create('/api/annees_scolaires', ['libelle' => '2027-2028', 'dateDebut' => '2027-09-01', 'dateFin' => '2028-07-15']);
        $autrePeriode = $this->create('/api/periodes', ['annee' => $autreAnnee, 'numero' => 1, 'libelle' => '1er trimestre', 'dateDebut' => '2027-09-01', 'dateFin' => '2027-12-15']);
        $this->client->request('GET', $this->awa.'/bulletin.pdf?periode='.basename($autrePeriode), [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokens['secretariat']]);
        self::assertResponseStatusCodeSame(400);

        // Classe sans élève : 404.
        $niveau = $this->call('GET', $this->classe, 'directeur')['niveau'];
        $vide = $this->create('/api/classes', ['annee' => $this->annee, 'niveau' => $niveau, 'libelle' => '6e B']);
        $this->client->request('GET', $vide.'/bulletins.pdf', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokens['secretariat']]);
        self::assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------------------------------------

    /** Télécharge un PDF (attend un 200 application/pdf) et renvoie son contenu. */
    private function pdf(string $uri, string $profil): string
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokens[$profil]]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF', $contenu);

        return $contenu;
    }

    /** Crée une ressource en tant que directeur (attend un 201) et renvoie son IRI. */
    private function create(string $uri, array $body): string
    {
        $data = $this->call('POST', $uri, 'directeur', $body);
        self::assertResponseStatusCodeSame(201, sprintf('POST %s : %s', $uri, json_encode($data, \JSON_UNESCAPED_UNICODE)));

        return $data['@id'];
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $uri, string $profil, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->tokens[$profil],
        ], null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function login(string $email): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['email' => $email, 'password' => self::PASSWORD], ['HTTP_X_TENANT' => self::SLUG]);
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
