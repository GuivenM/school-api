<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

/** Situation de l'élève vis-à-vis de l'école (indépendante de son inscription d'une année). */
enum StatutEleve: string
{
    case Actif = 'actif';
    case Transfere = 'transfere';
    case Sorti = 'sorti';
    case Diplome = 'diplome';
}
