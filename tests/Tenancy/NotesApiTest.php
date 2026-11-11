<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Évaluations, notes, moyennes, rangs, fermeture de trimestre et bulletin (lot 5A).
 * Prérequis : clés JWT générées et base centrale de test migrée.
 */
final class NotesApiTest extends WebTestCase
{
    private const SLUG = 'not_a';
    private const PASSWORD = 'Motdepasse-Test-1';

    private KernelBrowser $client;

    /** @var array<string, string> jeton par profil */
    private array $tokens = [];

    private string $t1;
    private string $t2;
    private string $classe;
    private string $cmMath;
    private string $cmFran;
    private string $awa;
    private string $yao;
    private string $zoe;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->cleanup();
        self::assertSame(0, $this->runCommand('tenant:create', ['slug' => self::SLUG, 'name' => 'École test']));
        foreach (['directeur', 'secretariat'] as $role) {
            self::assertSame(0, $this->runCommand('tenant:user:create', [
                'slug' => self::SLUG, 'email' => $role.'@not-a.test', 'role' => $role, '--password' => self::PASSWORD,
            ]));
            $this->tokens[$role] = $this->login($role.'@not-a.test');
        }

        // Deux enseignants avec leur compte : prof1 enseigne les maths, prof2 le français.
        foreach (['prof1' => 'Kone', 'prof2' => 'Diallo'] as $compte => $nom) {
            $enseignant = $this->create('/api/enseignants', ['nom' => $nom, 'prenoms' => 'Test']);
            $this->create('/api/utilisateurs', ['email' => $compte.'@not-a.test', 'role' => 'enseignant', 'plainPassword' => self::PASSWORD, 'enseignant' => $enseignant]);
            $this->tokens[$compte] = $this->login($compte.'@not-a.test');
            $enseignants[$compte] = $enseignant;
        }

        $niveau = $this->create('/api/niveaux', ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1]);
        $annee = $this->create('/api/annees_scolaires', ['libelle' => '2026-2027', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        $this->t1 = $this->create('/api/periodes', ['annee' => $annee, 'numero' => 1, 'libelle' => '1er trimestre', 'dateDebut' => '2026-09-01', 'dateFin' => '2026-12-15']);
        $this->t2 = $this->create('/api/periodes', ['annee' => $annee, 'numero' => 2, 'libelle' => '2e trimestre', 'dateDebut' => '2027-01-05', 'dateFin' => '2027-03-31']);
        $this->classe = $this->create('/api/classes', ['annee' => $annee, 'niveau' => $niveau, 'libelle' => '6e A']);
        $maths = $this->create('/api/matieres', ['code' => 'MATH', 'libelle' => 'Mathématiques']);
        $francais = $this->create('/api/matieres', ['code' => 'FRAN', 'libelle' => 'Français']);
        $this->cmMath = $this->create('/api/classe_matieres', ['classe' => $this->classe, 'matiere' => $maths, 'coefficient' => 2, 'enseignant' => $enseignants['prof1']]);
        $this->cmFran = $this->create('/api/classe_matieres', ['classe' => $this->classe, 'matiere' => $francais, 'coefficient' => 1, 'enseignant' => $enseignants['prof2']]);

        foreach (['awa' => 'Awa', 'yao' => 'Yao', 'zoe' => 'Zoe'] as $propriete => $prenoms) {
            $eleve = $this->create('/api/eleves', ['nom' => 'Koffi', 'prenoms' => $prenoms, 'sexe' => 'F', 'dateNaissance' => '2012-05-14']);
            $this->{$propriete} = $this->create('/api/inscriptions', ['eleve' => $eleve, 'annee' => $annee, 'classe' => $this->classe, 'type' => 'nouvelle']);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testUnEnseignantNeGereQueSesMatieres(): void
    {
        $eval = ['periode' => $this->t1, 'type' => 'devoir', 'libelle' => 'Devoir 1', 'date' => '2026-10-01'];

        $maths = $this->create('/api/evaluations', ['classeMatiere' => $this->cmMath] + $eval, 'prof1');

        $this->call('POST', '/api/evaluations', 'prof1', ['classeMatiere' => $this->cmFran] + $eval);
        self::assertResponseStatusCodeSame(403, 'prof1 sur le français');

        $this->call('PATCH', $maths, 'prof2', ['libelle' => 'Piraté']);
        self::assertResponseStatusCodeSame(403, 'prof2 modifie une évaluation de prof1');

        $this->call('POST', '/api/evaluations', 'secretariat', ['classeMatiere' => $this->cmFran] + $eval);
        self::assertResponseStatusCodeSame(201, 'le secrétariat peut tout saisir');

        $this->call('POST', '/api/notes', 'prof1', ['evaluation' => $maths, 'inscription' => $this->awa, 'valeur' => 14]);
        self::assertResponseStatusCodeSame(201);
        $this->call('POST', '/api/notes', 'prof2', ['evaluation' => $maths, 'inscription' => $this->yao, 'valeur' => 9]);
        self::assertResponseStatusCodeSame(403, 'prof2 note une évaluation de prof1');
    }

    public function testValidationsDesEvaluationsEtDesNotes(): void
    {
        $base = ['classeMatiere' => $this->cmMath, 'periode' => $this->t1, 'type' => 'devoir', 'libelle' => 'Devoir 1'];
        $this->call('POST', '/api/evaluations', 'prof1', $base + ['date' => '2027-02-01']);
        self::assertResponseStatusCodeSame(422, 'date hors du trimestre');

        $evaluation = $this->create('/api/evaluations', $base + ['date' => '2026-10-01'], 'prof1');
        $note = ['evaluation' => $evaluation, 'inscription' => $this->awa];

        $this->call('POST', '/api/notes', 'prof1', $note + ['valeur' => 21]);
        self::assertResponseStatusCodeSame(422, 'note au-dessus de 20');
        $this->call('POST', '/api/notes', 'prof1', $note + ['valeur' => -1]);
        self::assertResponseStatusCodeSame(422, 'note négative');
        $this->call('POST', '/api/notes', 'prof1', $note);
        self::assertResponseStatusCodeSame(422, 'note saisie sans valeur');
        $this->call('POST', '/api/notes', 'prof1', $note + ['statut' => 'absent', 'valeur' => 10]);
        self::assertResponseStatusCodeSame(422, 'absent avec une valeur');
        $this->call('POST', '/api/notes', 'prof1', $note + ['valeur' => 14.555]);
        self::assertResponseStatusCodeSame(422, 'plus de deux décimales');

        $this->call('POST', '/api/notes', 'prof1', $note + ['statut' => 'absent']);
        self::assertResponseStatusCodeSame(201, 'absent sans valeur');
        $this->call('POST', '/api/notes', 'prof1', $note + ['valeur' => 12]);
        self::assertResponseStatusCodeSame(422, 'deuxième note pour le même élève');

        // Élève d'une autre classe : refusé.
        $autreClasse = $this->create('/api/classes', ['annee' => $this->anneeDe($this->classe), 'niveau' => $this->niveauDe($this->classe), 'libelle' => '6e B']);
        $eleveB = $this->create('/api/eleves', ['nom' => 'Autre', 'prenoms' => 'Eleve', 'sexe' => 'M', 'dateNaissance' => '2012-01-01']);
        $inscriptionB = $this->create('/api/inscriptions', ['eleve' => $eleveB, 'annee' => $this->anneeDe($this->classe), 'classe' => $autreClasse, 'type' => 'nouvelle']);
        $this->call('POST', '/api/notes', 'prof1', ['evaluation' => $evaluation, 'inscription' => $inscriptionB, 'valeur' => 10]);
        self::assertResponseStatusCodeSame(422, 'élève d\'une autre classe');
    }

    public function testSaisieEnLotToutOuRien(): void
    {
        $evaluation = $this->create('/api/evaluations', ['classeMatiere' => $this->cmMath, 'periode' => $this->t1, 'type' => 'devoir', 'libelle' => 'Devoir 1', 'date' => '2026-10-01'], 'prof1');
        $url = $evaluation.'/notes';

        $data = $this->call('POST', $url, 'prof1', ['notes' => [
            ['inscription' => $this->awa, 'valeur' => 14],
            ['inscription' => $this->yao, 'valeur' => 8.5],
            ['inscription' => $this->zoe, 'statut' => 'absent'],
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $data['enregistrees']);

        // Correction : les notes existantes sont remplacées, pas dupliquées.
        $this->call('POST', $url, 'prof1', ['notes' => [['inscription' => $this->awa, 'valeur' => 15]]]);
        self::assertResponseIsSuccessful();
        $liste = $this->call('GET', '/api/notes?evaluation='.$evaluation, 'prof1');
        $notes = $liste['hydra:member'] ?? $liste['member'];
        self::assertCount(3, $notes);
        $valeurs = array_column($notes, 'valeur');
        self::assertContains(15, array_map('intval', array_filter($valeurs, 'is_numeric')));

        // Une seule ligne invalide : rien n'est enregistré.
        $data = $this->call('POST', $url, 'prof1', ['notes' => [
            ['inscription' => $this->yao, 'valeur' => 3],
            ['inscription' => $this->awa, 'valeur' => 25],
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $data['violations'][0]['index']);
        $liste = $this->call('GET', '/api/notes?evaluation='.$evaluation, 'prof1');
        $notes = $liste['hydra:member'] ?? $liste['member'];
        self::assertNotContains(3, array_map('intval', array_filter(array_column($notes, 'valeur'), 'is_numeric')), 'la note valide du même envoi ne doit pas être enregistrée');

        $this->call('POST', $url, 'prof1', ['notes' => [['inscription' => $this->awa, 'valeur' => 10], ['inscription' => $this->awa, 'valeur' => 11]]]);
        self::assertResponseStatusCodeSame(422, 'même élève deux fois');

        $this->call('POST', $url, 'prof2', ['notes' => [['inscription' => $this->awa, 'valeur' => 10]]]);
        self::assertResponseStatusCodeSame(403, 'enseignant d\'une autre matière');
    }

    public function testMoyennesRangsFermetureEtVerrouillage(): void
    {
        $this->saisirLAnnee();
        $idClasse = basename($this->classe);

        // Notes sur 20 : moyenne simple par matière, puis moyenne pondérée par les coefficients (maths 2, français 1).
        $data = $this->call('GET', $this->t1.'/resultats?classe='.$idClasse, 'prof2');
        self::assertResponseIsSuccessful();
        self::assertSame('calcul', $data['source']);
        self::assertSame(3, $data['effectif']);
        $awa = $this->ligne($data, 'Awa');
        $yao = $this->ligne($data, 'Yao');
        $zoe = $this->ligne($data, 'Zoe');
        self::assertEqualsWithDelta(13.33, $awa['moyenne'], 0.001);
        self::assertSame(1, $awa['rang']);
        self::assertEqualsWithDelta(13.0, $zoe['moyenne'], 0.001, 'la note « absent » est ignorée, pas comptée 0');
        self::assertSame(2, $zoe['rang']);
        self::assertEqualsWithDelta(10.0, $yao['moyenne'], 0.001);
        self::assertSame(3, $yao['rang']);
        self::assertEqualsWithDelta(15.0, $awa['matieres'][0]['moyenne'], 0.001, 'maths d\'Awa : (14 + 16) / 2');

        // Fermeture : réservée au secrétariat/directeur ; fige les résultats et verrouille la saisie.
        $this->call('POST', $this->t1.'/fermer', 'prof1');
        self::assertResponseStatusCodeSame(403);
        $fermeture = $this->call('POST', $this->t1.'/fermer', 'secretariat');
        self::assertResponseIsSuccessful();
        self::assertSame(3, $fermeture['resultats']);
        $this->call('POST', $this->t1.'/fermer', 'secretariat');
        self::assertResponseStatusCodeSame(409, 'déjà fermé');

        $data = $this->call('GET', $this->t1.'/resultats?classe='.$idClasse, 'prof2');
        self::assertSame('snapshot', $data['source']);

        // Modifier un coefficient après la fermeture ne change pas le bulletin déjà figé.
        $this->call('PATCH', $this->cmMath, 'secretariat', ['coefficient' => 5]);
        self::assertResponseIsSuccessful();
        $data = $this->call('GET', $this->t1.'/resultats?classe='.$idClasse, 'prof2');
        self::assertEqualsWithDelta(13.33, $this->ligne($data, 'Awa')['moyenne'], 0.001);

        // Trimestre fermé : plus de nouvelle note, plus de modification d'évaluation, plus de suppression du trimestre.
        $evaluations = $this->call('GET', '/api/evaluations?periode='.$this->t1, 'secretariat');
        $premiere = ($evaluations['hydra:member'] ?? $evaluations['member'])[0]['@id'];
        $this->call('PATCH', $premiere, 'secretariat', ['libelle' => 'Modifiée']);
        self::assertResponseStatusCodeSame(403, 'évaluation d\'un trimestre fermé');
        $this->call('DELETE', $this->t1, 'secretariat');
        self::assertResponseStatusCodeSame(403, 'suppression d\'un trimestre fermé');

        // Réouverture : directeur seulement ; les résultats sont recalculés avec le coefficient actuel (5).
        $this->call('POST', $this->t1.'/rouvrir', 'secretariat');
        self::assertResponseStatusCodeSame(403);
        $this->call('POST', $this->t1.'/rouvrir', 'directeur');
        self::assertResponseIsSuccessful();

        $data = $this->call('GET', $this->t1.'/resultats?classe='.$idClasse, 'prof2');
        self::assertSame('calcul', $data['source']);
        self::assertEqualsWithDelta(14.17, $this->ligne($data, 'Awa')['moyenne'], 0.001, '(15×5 + 10×1) / 6');
    }

    public function testBulletinAvecMoyenneAnnuelle(): void
    {
        $this->saisirLAnnee();

        $bulletin = $this->call('GET', $this->awa.'/bulletin', 'secretariat');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $bulletin['periodes']);
        self::assertEqualsWithDelta(13.33, $bulletin['periodes'][0]['moyenne'], 0.001);
        self::assertEqualsWithDelta(16.67, $bulletin['periodes'][1]['moyenne'], 0.001);
        // Annuelle = moyenne des moyennes de trimestre existantes : (13,33 + 16,67) / 2.
        self::assertEqualsWithDelta(15.0, $bulletin['moyenneAnnuelle'], 0.001);
        self::assertSame(1, $bulletin['rangAnnuel']);
        self::assertSame(3, $bulletin['effectifAnnuel']);

        // Zoe n'a de notes qu'au 1er trimestre : sa moyenne annuelle est calculée sur 1 trimestre, pas sur 2 avec un zéro.
        $bulletin = $this->call('GET', $this->zoe.'/bulletin', 'secretariat');
        self::assertEqualsWithDelta(13.0, $bulletin['moyenneAnnuelle'], 0.001);
        self::assertSame(2, $bulletin['rangAnnuel']);
        self::assertNull($bulletin['periodes'][1]['moyenne']);
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * 1er trimestre : maths = 2 devoirs, français = 1 devoir. 2e trimestre : un devoir de maths pour Awa seulement.
     * Awa : maths 14 et 16, français 10 ; Yao : 8 et 10, 12 ; Zoe : 12 et absent, 15.
     */
    private function saisirLAnnee(): void
    {
        $devoir = static fn (string $cm, string $periode, string $libelle, string $date): array => ['classeMatiere' => $cm, 'periode' => $periode, 'type' => 'devoir', 'libelle' => $libelle, 'date' => $date];

        $m1 = $this->create('/api/evaluations', $devoir($this->cmMath, $this->t1, 'Devoir 1', '2026-10-01'), 'prof1');
        $m2 = $this->create('/api/evaluations', $devoir($this->cmMath, $this->t1, 'Devoir 2', '2026-11-01'), 'prof1');
        $f1 = $this->create('/api/evaluations', $devoir($this->cmFran, $this->t1, 'Devoir 1', '2026-10-10'), 'prof2');
        $m3 = $this->create('/api/evaluations', $devoir($this->cmMath, $this->t2, 'Devoir 1', '2027-02-01'), 'prof1');

        $this->saisir($m1, 'prof1', [[$this->awa, 14], [$this->yao, 8], [$this->zoe, 12]]);
        $this->saisir($m2, 'prof1', [[$this->awa, 16], [$this->yao, 10], [$this->zoe, null]]);
        $this->saisir($f1, 'prof2', [[$this->awa, 10], [$this->yao, 12], [$this->zoe, 15]]);
        $this->saisir($m3, 'prof1', [[$this->awa, 16.67]]);
    }

    /** @param list<array{0: string, 1: int|float|null}> $notes une valeur null = absent */
    private function saisir(string $evaluation, string $profil, array $notes): void
    {
        $this->call('POST', $evaluation.'/notes', $profil, ['notes' => array_map(
            static fn (array $n): array => null === $n[1] ? ['inscription' => $n[0], 'statut' => 'absent'] : ['inscription' => $n[0], 'valeur' => $n[1]],
            $notes,
        )]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function ligne(array $resultats, string $prenoms): array
    {
        foreach ($resultats['eleves'] as $ligne) {
            if ($prenoms === $ligne['eleve']['prenoms']) {
                return $ligne;
            }
        }
        self::fail('Élève introuvable dans les résultats : '.$prenoms);
    }

    private function anneeDe(string $classe): string
    {
        return $this->call('GET', $classe, 'secretariat')['annee'];
    }

    private function niveauDe(string $classe): string
    {
        return $this->call('GET', $classe, 'secretariat')['niveau'];
    }

    /** Crée une ressource (attend un 201) et renvoie son IRI. */
    private function create(string $uri, array $body, string $profil = 'directeur'): string
    {
        $data = $this->call('POST', $uri, $profil, $body);
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
