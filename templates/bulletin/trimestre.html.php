<?php

declare(strict_types=1);

/** @var array<string, mixed> $b bulletin complet */
/** @var array<string, mixed> $bloc bloc du trimestre */
/** @var bool $provisoire */
require_once __DIR__.'/_fonctions.php';
$e = 'bulletin_e';
?>
<table class="entete"><tr>
    <td class="gauche"><strong><?= $e($b['ecole']) ?></strong></td>
    <td class="centre">
        <div class="titre">BULLETIN DE NOTES</div>
        <div><?= $e($bloc['libelle']) ?> — année scolaire <?= $e($b['anneeLibelle']) ?></div>
    </td>
    <td class="droite">Classe : <strong><?= $e($b['classeLibelle']) ?></strong><br>Élèves classés : <?= $e($bloc['effectif']) ?></td>
</tr></table>
<?php if ($provisoire) { ?><div class="provisoire">BULLETIN PROVISOIRE — trimestre non clôturé</div><?php } ?>

<table class="eleve">
    <tr><td class="lib">Nom et prénoms</td><td><strong><?= $e($b['eleve']['nom']) ?> <?= $e($b['eleve']['prenoms']) ?></strong></td>
        <td class="lib">Matricule</td><td><?= $e($b['eleve']['matricule'] ?? '') ?></td></tr>
    <tr><td class="lib">Né(e) le</td><td><?= $e(bulletin_date($b['eleve']['dateNaissance'] ?? null)) ?></td>
        <td class="lib">Sexe</td><td><?= $e($b['eleve']['sexe'] ?? '') ?></td></tr>
</table>

<table class="notes">
    <thead><tr><th>Matières</th><th>Coef.</th><th>Moyenne /20</th><th>Rang</th><th>Appréciation</th></tr></thead>
    <tbody>
    <?php foreach ($bloc['matieres'] as $matiere) { ?>
        <tr>
            <td><?= $e($matiere['libelle']) ?></td>
            <td class="num"><?= $e(bulletin_coef((float) $matiere['coefficient'])) ?></td>
            <td class="num"><?= $e(bulletin_nombre($matiere['moyenne'])) ?></td>
            <td class="num"><?= $e(bulletin_rang($matiere['rang'])) ?></td>
            <td class="appr"><?= $e($matiere['appreciation'] ?? '') ?></td>
        </tr>
    <?php } ?>
        <tr class="total">
            <td colspan="2">MOYENNE GÉNÉRALE</td>
            <td class="num"><?= $e(bulletin_nombre($bloc['moyenne'])) ?> / 20</td>
            <td class="num" colspan="2">Rang : <?= $e(bulletin_rang($bloc['rang'], $bloc['effectif'])) ?></td>
        </tr>
    </tbody>
</table>

<div class="boite"><div class="t">Appréciation du conseil de classe</div><?= $e($bloc['appreciation'] ?? '') ?></div>

<table class="signatures"><tr>
    <td>Le titulaire de classe</td>
    <td>Le Directeur</td>
    <td>Le parent ou tuteur<br><small>(vu et pris connaissance)</small></td>
</tr></table>
<div class="pied">Notes sur 20 · moyenne de matière = moyenne simple des évaluations · moyenne générale pondérée par les coefficients</div>
