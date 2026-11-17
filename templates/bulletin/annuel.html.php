<?php

declare(strict_types=1);

/** @var array<string, mixed> $b bulletin complet */
/** @var bool $provisoire */
require_once __DIR__.'/_fonctions.php';
$e = 'bulletin_e';

$libelleDecision = static function (?string $decision, bool $finDeCycle): string {
    return match ($decision) {
        'admis' => $finDeCycle ? 'Fin de cycle : admis(e)' : 'Admis(e) en classe supérieure',
        'redouble' => 'Redouble la classe',
        'exclu' => 'Exclu(e)',
        default => 'Décision de fin d\'année : en attente',
    };
};
?>
<table class="entete"><tr>
    <td class="gauche"><strong><?= $e($b['ecole']) ?></strong></td>
    <td class="centre">
        <div class="titre">BULLETIN ANNUEL</div>
        <div>Année scolaire <?= $e($b['anneeLibelle']) ?></div>
    </td>
    <td class="droite">Classe : <strong><?= $e($b['classeLibelle']) ?></strong><br>Élèves classés : <?= $e($b['effectifAnnuel']) ?></td>
</tr></table>
<?php if ($provisoire) { ?><div class="provisoire">BULLETIN PROVISOIRE — année non terminée</div><?php } ?>

<table class="eleve">
    <tr><td class="lib">Nom et prénoms</td><td><strong><?= $e($b['eleve']['nom']) ?> <?= $e($b['eleve']['prenoms']) ?></strong></td>
        <td class="lib">Matricule</td><td><?= $e($b['eleve']['matricule'] ?? '') ?></td></tr>
    <tr><td class="lib">Né(e) le</td><td><?= $e(bulletin_date($b['eleve']['dateNaissance'] ?? null)) ?></td>
        <td class="lib">Sexe</td><td><?= $e($b['eleve']['sexe'] ?? '') ?></td></tr>
</table>

<table class="notes">
    <thead><tr><th>Trimestre</th><th>Moyenne /20</th><th>Rang</th><th>Appréciation du conseil de classe</th></tr></thead>
    <tbody>
    <?php foreach ($b['periodes'] as $bloc) { ?>
        <tr>
            <td><?= $e($bloc['libelle']) ?></td>
            <td class="num"><?= $e(bulletin_nombre($bloc['moyenne'])) ?></td>
            <td class="num"><?= $e(bulletin_rang($bloc['rang'], $bloc['effectif'])) ?></td>
            <td class="appr"><?= $e($bloc['appreciation'] ?? '') ?></td>
        </tr>
    <?php } ?>
        <tr class="total">
            <td>MOYENNE ANNUELLE</td>
            <td class="num"><?= $e(bulletin_nombre($b['moyenneAnnuelle'])) ?> / 20</td>
            <td class="num" colspan="2">Rang : <?= $e(bulletin_rang($b['rangAnnuel'], $b['effectifAnnuel'])) ?></td>
        </tr>
    </tbody>
</table>

<div class="decision"><?= $e($libelleDecision($b['decision'], (bool) $b['finDeCycle'])) ?></div>

<table class="signatures"><tr>
    <td>Le titulaire de classe</td>
    <td>Le Directeur</td>
    <td>Le parent ou tuteur<br><small>(vu et pris connaissance)</small></td>
</tr></table>
<div class="pied">La moyenne annuelle est la moyenne des moyennes de trimestre · notes sur 20</div>
