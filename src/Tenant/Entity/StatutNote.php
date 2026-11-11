<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

/** Seules les notes « saisie » entrent dans les moyennes ; les autres statuts sont ignorés (et non comptés comme zéro). */
enum StatutNote: string
{
    case Saisie = 'saisie';
    case Absent = 'absent';
    case Dispense = 'dispense';
    case NonEvalue = 'non_evalue';
}
