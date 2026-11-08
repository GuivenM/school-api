<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

enum StatutPeriode: string
{
    case Ouverte = 'ouverte';
    case Fermee = 'fermee';
}
