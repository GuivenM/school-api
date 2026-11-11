<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** À poser sur Utilisateur : une école doit toujours conserver au moins un directeur actif (sinon plus personne ne gère les comptes). */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UnDirecteurActifAuMoins extends Constraint
{
    public string $message = 'Il doit rester au moins un directeur actif.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
