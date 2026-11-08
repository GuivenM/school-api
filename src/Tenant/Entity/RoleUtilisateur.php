<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

/** Rôle d'un utilisateur au sein d'une école (voir role_hierarchy dans security.yaml). */
enum RoleUtilisateur: string
{
    case Directeur = 'directeur';
    case Secretariat = 'secretariat';
    case Comptable = 'comptable';
    case Enseignant = 'enseignant';
    case Tuteur = 'tuteur';
    case Eleve = 'eleve';

    public function securityRole(): string
    {
        return 'ROLE_'.strtoupper($this->value);
    }
}
