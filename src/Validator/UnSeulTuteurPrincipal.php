<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** À poser sur EleveTuteur : un élève a au plus un tuteur « principal ». */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UnSeulTuteurPrincipal extends Constraint
{
    public string $message = 'Cet élève a déjà un tuteur principal.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
