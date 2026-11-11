<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

/** Sert à l'affichage et aux statistiques : n'intervient pas dans le calcul (moyenne simple de toutes les évaluations). */
enum TypeEvaluation: string
{
    case Interrogation = 'interrogation';
    case Devoir = 'devoir';
    case Composition = 'composition';
}
