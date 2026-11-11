<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

enum TypeInscription: string
{
    case Nouvelle = 'nouvelle';
    case Reinscription = 'reinscription';
    case Redoublement = 'redoublement';
    case TransfertEntrant = 'transfert_entrant';
}
