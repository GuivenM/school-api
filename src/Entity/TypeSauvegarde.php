<?php

declare(strict_types=1);

namespace App\Entity;

enum TypeSauvegarde: string
{
    case Quotidienne = 'quotidienne';
    case Manuelle = 'manuelle';
    /** Point de retour avant une clôture d'année (irréversible). */
    case AvantCloture = 'avant_cloture';
    /** Archive figée de l'année une fois clôturée. */
    case ArchiveAnnee = 'archive_annee';
    /** Filet de sécurité pris automatiquement juste avant une restauration. */
    case AvantRestauration = 'avant_restauration';

    /** Durée de conservation en jours ; null = jamais supprimée automatiquement. */
    public function retentionJours(): ?int
    {
        return match ($this) {
            self::Quotidienne => 14,
            self::Manuelle => 90,
            self::AvantCloture => 365,
            self::AvantRestauration => 30,
            self::ArchiveAnnee => null,
        };
    }
}
