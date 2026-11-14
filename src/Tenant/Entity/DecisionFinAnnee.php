<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

/**
 * Décision de fin d'année pour un élève. « admis » = admis à passer ; pour le dernier niveau d'une école (aucun niveau suivant),
 * c'est une fin de cycle : l'école ne gère pas les examens d'État (BEPC, BAC), l'élève sort simplement de l'école.
 */
enum DecisionFinAnnee: string
{
    case Admis = 'admis';
    case Redouble = 'redouble';
    case Exclu = 'exclu';
}
