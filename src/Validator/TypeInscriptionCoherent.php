<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** À poser sur Inscription : « nouvelle » = première inscription de l'élève ; réinscription/redoublement = il a déjà un parcours. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TypeInscriptionCoherent extends Constraint
{
    public string $messageNouvelle = 'Cet élève a déjà été inscrit : utiliser « reinscription », « redoublement » ou « transfert_entrant ».';
    public string $messageSuite = 'Cet élève n\'a aucune inscription précédente : utiliser « nouvelle » ou « transfert_entrant ».';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
