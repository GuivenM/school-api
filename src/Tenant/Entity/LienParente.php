<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

enum LienParente: string
{
    case Pere = 'pere';
    case Mere = 'mere';
    case Tuteur = 'tuteur';
    case Autre = 'autre';
}
