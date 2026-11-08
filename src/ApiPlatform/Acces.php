<?php

declare(strict_types=1);

namespace App\ApiPlatform;

/**
 * Expressions de sécurité partagées par les ressources API Platform.
 * ROLE_SECRETARIAT inclut le directeur (role_hierarchy dans security.yaml).
 */
final class Acces
{
    public const LECTURE = "is_granted('ROLE_USER')";
    public const ECRITURE = "is_granted('ROLE_SECRETARIAT')";
    /** Données personnelles (élèves mineurs, tuteurs, enseignants) : réservées au personnel, pas aux comptes tuteur/élève. */
    public const PERSONNEL = "is_granted('ROLE_SECRETARIAT') or is_granted('ROLE_COMPTABLE') or is_granted('ROLE_ENSEIGNANT')";

    private function __construct()
    {
    }
}
