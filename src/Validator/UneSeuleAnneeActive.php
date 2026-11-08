<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** À poser sur AnneeScolaire : une seule année peut être « active » à la fois. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UneSeuleAnneeActive extends Constraint
{
    public string $message = 'Une autre année scolaire est déjà active.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
