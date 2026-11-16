<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Décisions de fin d'année, simulation et clôture de l'année (lot 6) via l'API.
 * Prérequis : clés JWT générées et base centrale de test migrée.
 */
final class ClotureAnneeApiTest extends WebTestCase
{
    private const SLUG = 'clo_a';
    private const PASSWORD = 'Motdepasse-Test-1';

    private KernelBrowser $client;

    /** @var array<string, string> */
    private array $tokens = [];

    private string $annee;
    private string $suivante;
    private string $t1;
    private string $t2;
    private string $classe6;
    private string $classe5;
    /** @var array<string, string> inscriptions par prénom */
    private array $inscriptions = [];
    /** @var array<string, string> élèves par prénom */
    private array $eleves = [];

    protected function setUp(): void
    {
        // Ces tests ne lancent pas mysqldump : la sauvegarde automatique à la clôture est désactivée ici.
        putenv('BACKUP_ON_CLOSURE=0');
        $_ENV['BACKUP_ON_CLOSURE'] = $_SERVER['BACKUP_ON_CLOSURE'] = '0';

        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->cleanup();
        self::assertSame(0, $this->runCommand('tenant:create', ['slug' => self::SLUG, 'name' => 'École test']));
        foreach (['directeur', 'secretariat'] as $role) {
            self::assertSame(0, $this->runCommand('tenant:user:create', [
                'slug' => self::SLUG, 'email' => $role.'@clo-a.test', 'role' => $role, '--password' => self::PASSWORD,
            ]));
            $this->tokens[$role] = $this->login($role.'@clo-a.test');
        }

        // 6e → 5e (dernier niveau de cet établissement : un élève admis en 5e termine son cycle).
        $niveau5 = $this->create('/api/niveaux', ['code' => '5E', 'libelle' => 'Cinquième', 'ordre' => 2]);
        $niveau6 = $this->create('/api/niveaux', ['code' => '6E', 'libelle' => 'Sixième', 'ordre' => 1, 'niveauSuivant' => $niveau5]);
        $maths = $this->create('/api/matieres', ['code' => 'MATH', 'libelle' => 'Mathématiques']);

        $this->annee = $this->create('/api/annees_scolaires', ['libelle' => '2026-2027', 'dateDebut' => '2026-09-01', 'dateFin' => '2027-07-15']);
        $this->call('PATCH', $this->annee, 'directeur', ['statut' => 'active']);
        self::assertResponseIsSuccessful();
        $this->t1 = $this->create('/api/periodes', ['annee' => $this->annee, 'numero' => 1, 'libelle' => '1er trimestre', 'dateDebut' => '2026-09-01', 'dateFin' => '2026-12-15']);
        $this->t2 = $this->create('/api/periodes', ['annee' => $this->annee, 'numero' => 2, 'libelle' => '2e trimestre', 'dateDebut' => '2027-01-05', 'dateFin' => '2027-03-31']);
        $this->classe6 = $this->create('/api/classes', ['annee' => $this->annee, 'niveau' => $niveau6, 'libelle' => '6e A']);
        $this->classe5 = $this->create('/api/classes', ['annee' => $this->annee, 'niveau' => $niveau5, 'libelle' => '5e A']);
        $cm6 = $this->create('/api/classe_matieres', ['classe' => $this->classe6, 'matiere' => $maths, 'coefficient' => 1]);
        $cm5 = $this->create('/api/classe_matieres', ['classe' => $this->classe5, 'matiere' => $maths, 'coefficient' => 1]);

        foreach (['Awa' => $this->classe6, 'Yao' => $this->classe6, 'Zoe' => $this->classe6, 'Eve' => $this->classe6, 'Kofi' => $this->classe5] as $prenoms => $classe) {
            $this->eleves[$prenoms] = $this->create('/api/eleves', ['nom' => 'Test', 'prenoms' => $prenoms, 'sexe' => 'F', 'dateNaissance' => '2012-05-14']);
            $this->inscriptions[$prenoms] = $this->create('/api/inscriptions', ['eleve' => $this->eleves[$prenoms], 'annee' => $this->annee, 'classe' => $classe, 'type' => 'nouvelle']);
        }

        // Moyennes annuelles : Awa 14, Yao 8, Eve 11, Kofi 12 ; Zoe n'a aucune note.
        foreach ([[$cm6, $this->t1, '2026-10-01'], [$cm6, $this->t2, '2027-02-01']] as [$cm, $periode, $date]) {
            $this->saisir($cm, $periode, $date, [['Awa', 14], ['Yao', 8], ['Eve', 11]]);
        }
        foreach ([[$cm5, $this->t1, '2026-10-01'], [$cm5, $this->t2, '2027-02-01']] as [$cm, $periode, $date]) {
            $this->saisir($cm, $periode, $date, [['Kofi', 12]]);
        }

        $this->suivante = $this->create('/api/annees_scolaires', ['libelle' => '2027-2028', 'dateDebut' => '2027-09-01', 'dateFin' => '2028-07-15']);
        $this->create('/api/classes', ['annee' => $this->suivante, 'niveau' => $niveau6, 'libelle' => '6e A']);
        $this->create('/api/classes', ['annee' => $this->suivante, 'niveau' => $niveau5, 'libelle' => '5e A']);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testSimulationEtBlocages(): void
    {
        $apercu = $this->call('POST', $this->annee.'/cloture/apercu', 'secretariat', ['anneeSuivante' => $this->suivante]);
        self::assertResponseIsSuccessful();
        self::assertFalse($apercu['pret']);
        self::assertContains('trimestre_ouvert', array_column($apercu['blocages'], 'code'));

        foreach ([$this->t1, $this->t2] as $periode) {
            $this->call('POST', $periode.'/fermer', 'secretariat');
            self::assertResponseIsSuccessful();
        }

        $apercu = $this->call('POST', $this->annee.'/cloture/apercu', 'secretariat', ['anneeSuivante' => $this->suivante]);
        self::assertSame(['decision_requise'], array_column($apercu['blocages'], 'code'), 'seule Zoe, sans moyenne, bloque');

        $lignes = $this->lignes($apercu);
        self::assertSame('passage', $lignes['Awa']['devenir']);
        self::assertEqualsWithDelta(14.0, $lignes['Awa']['moyenneAnnuelle'], 0.001);
        self::assertSame('redoublement', $lignes['Yao']['devenir']);
        self::assertSame('fin_de_cycle', $lignes['Kofi']['devenir'], 'dernier niveau de l\'école : pas de niveau suivant');
        self::assertSame('decision_requise', $lignes['Zoe']['devenir']);

        // La simulation n'a rien modifié.
        self::assertSame('active', $this->call('GET', $this->annee, 'secretariat')['statut']);
    }

    public function testDecisionDuConseilDeClasse(): void
    {
        $this->fermerLesTrimestres();

        $this->call('POST', $this->inscriptions['Yao'].'/decision', 'secretariat', ['decision' => 'admis']);
        self::assertResponseStatusCodeSame(422, 'dérogation sans motif');
        $this->call('POST', $this->inscriptions['Yao'].'/decision', 'secretariat', ['decision' => 'inconnue', 'motif' => 'Test']);
        self::assertResponseStatusCodeSame(422, 'décision inconnue');

        $data = $this->call('POST', $this->inscriptions['Yao'].'/decision', 'secretariat', ['decision' => 'admis', 'motif' => 'Progrès constants, avis favorable du conseil']);
        self::assertResponseIsSuccessful();
        self::assertSame('redouble', $data['decisionCalculee']);
        self::assertSame('admis', $data['decisionConseil']);
        self::assertSame('secretariat@clo-a.test', $data['par']);

        $apercu = $this->call('POST', $this->annee.'/cloture/apercu', 'secretariat', ['anneeSuivante' => $this->suivante]);
        self::assertSame('passage', $this->lignes($apercu)['Yao']['devenir']);

        $data = $this->call('DELETE', $this->inscriptions['Yao'].'/decision', 'secretariat');
        self::assertResponseIsSuccessful();
        self::assertNull($data['decisionConseil']);

        $this->call('POST', $this->inscriptions['Zoe'].'/decision', 'secretariat', ['decision' => 'redouble']);
        self::assertResponseStatusCodeSame(422, 'sans moyenne, un motif est obligatoire même pour redoubler');
        $this->call('POST', $this->inscriptions['Zoe'].'/decision', 'secretariat', ['decision' => 'redouble', 'motif' => 'Arrivée tardive']);
        self::assertResponseIsSuccessful();
    }

    public function testClotureEtPassageALAnneeSuivante(): void
    {
        $this->fermerLesTrimestres();
        $this->call('POST', $this->inscriptions['Zoe'].'/decision', 'secretariat', ['decision' => 'redouble', 'motif' => 'Arrivée tardive']);
        $this->call('POST', $this->inscriptions['Eve'].'/decision', 'secretariat', ['decision' => 'exclu', 'motif' => 'Décision du conseil de discipline']);

        $demande = ['anneeSuivante' => $this->suivante];
        $this->call('POST', $this->annee.'/cloturer', 'secretariat', $demande);
        self::assertResponseStatusCodeSame(403, 'la clôture est réservée au directeur');

        $data = $this->call('POST', $this->annee.'/cloturer', 'directeur', $demande);
        self::assertResponseIsSuccessful(json_encode($data, \JSON_UNESCAPED_UNICODE));
        self::assertSame('cloturee', $data['statut']);
        self::assertSame(['passages' => 1, 'redoublements' => 2, 'fins_de_cycle' => 1, 'exclusions' => 1, 'sans_decision' => 0], $data['resume']);

        // Bilan figé sur les inscriptions de l'année clôturée.
        $awa = $this->call('GET', $this->inscriptions['Awa'], 'secretariat');
        self::assertSame('terminee', $awa['statut']);
        self::assertEqualsWithDelta(14.0, $awa['moyenneAnnuelle'], 0.001);
        self::assertSame(1, $awa['rangAnnuel']);
        self::assertSame('admis', $awa['decision']);

        // Nouvelles inscriptions : Awa passe en 5e, Yao et Zoe redoublent la 6e ; Eve (exclue) et Kofi (fin de cycle) sortent.
        $nouvelles = $this->call('GET', '/api/inscriptions?annee='.$this->suivante, 'secretariat');
        $membres = $nouvelles['hydra:member'] ?? $nouvelles['member'];
        self::assertCount(3, $membres);
        $types = array_count_values(array_column($membres, 'type'));
        self::assertEquals(['reinscription' => 1, 'redoublement' => 2], $types);

        self::assertSame('sorti', $this->call('GET', $this->eleves['Eve'], 'secretariat')['statut']);
        self::assertSame('sorti', $this->call('GET', $this->eleves['Kofi'], 'secretariat')['statut']);
        self::assertSame('actif', $this->call('GET', $this->eleves['Awa'], 'secretariat')['statut']);

        // Année clôturée = lecture seule.
        $this->call('PATCH', $this->classe6, 'directeur', ['libelle' => 'Modifiée']);
        self::assertResponseStatusCodeSame(403, 'classe d\'une année clôturée');
        $this->call('POST', '/api/classes', 'directeur', ['annee' => $this->annee, 'niveau' => $this->call('GET', $this->classe6, 'directeur')['niveau'], 'libelle' => '6e Z']);
        self::assertResponseStatusCodeSame(422, 'nouvelle classe dans une année clôturée');
        $this->call('PATCH', $this->annee, 'directeur', ['libelle' => '2026-2027']);
        self::assertResponseStatusCodeSame(403, 'année clôturée');

        // Pas de seconde clôture ; l'année suivante peut maintenant devenir active.
        $this->call('POST', $this->annee.'/cloturer', 'directeur', $demande);
        self::assertResponseStatusCodeSame(409);
        $this->call('PATCH', $this->suivante, 'directeur', ['statut' => 'active']);
        self::assertResponseIsSuccessful();
    }

    public function testLesBlocagesEmpechentLaCloture(): void
    {
        $data = $this->call('POST', $this->annee.'/cloturer', 'directeur', ['anneeSuivante' => $this->suivante]);
        self::assertResponseStatusCodeSame(409);
        self::assertNotEmpty($data['blocages']);
        self::assertSame('active', $this->call('GET', $this->annee, 'directeur')['statut'], 'rien n\'a été modifié');

        $this->call('POST', $this->annee.'/cloturer', 'directeur');
        self::assertResponseStatusCodeSame(409, 'année suivante manquante');
    }

    // ---------------------------------------------------------------------------------------------

    private function fermerLesTrimestres(): void
    {
        foreach ([$this->t1, $this->t2] as $periode) {
            $this->call('POST', $periode.'/fermer', 'secretariat');
            self::assertResponseIsSuccessful();
        }
    }

    /** @return array<string, array<string, mixed>> lignes d'aperçu indexées par prénom */
    private function lignes(array $apercu): array
    {
        $lignes = [];
        foreach ($apercu['classes'] as $classe) {
            foreach ($classe['eleves'] as $ligne) {
                $lignes[$ligne['eleve']['prenoms']] = $ligne;
            }
        }

        return $lignes;
    }

    /** @param list<array{0: string, 1: int|float}> $notes [prénom, note] */
    private function saisir(string $classeMatiere, string $periode, string $date, array $notes): void
    {
        $evaluation = $this->create('/api/evaluations', ['classeMatiere' => $classeMatiere, 'periode' => $periode, 'type' => 'devoir', 'libelle' => 'Devoir', 'date' => $date]);
        $this->call('POST', $evaluation.'/notes', 'directeur', ['notes' => array_map(
            fn (array $n): array => ['inscription' => $this->inscriptions[$n[0]], 'valeur' => $n[1]],
            $notes,
        )]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
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
