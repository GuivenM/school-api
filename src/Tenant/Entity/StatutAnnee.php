<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

enum StatutAnnee: string
{
    case Brouillon = 'brouillon';
    case Active = 'active';
    case Cloturee = 'cloturee';
    case Archivee = 'archivee';
}
