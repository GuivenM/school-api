<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

enum StatutInscription: string
{
    case EnCours = 'en_cours';
    /** Posé par la clôture d'année (lot 6), jamais par l'API. */
    case Terminee = 'terminee';
    case Abandonnee = 'abandonnee';
    case Transferee = 'transferee';
}
