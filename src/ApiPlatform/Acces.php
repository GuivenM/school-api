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
    /** Gestion des comptes de connexion : directeur uniquement (le secrétariat ne peut pas créer de directeur). */
    public const ADMINISTRATION = "is_granted('ROLE_DIRECTEUR')";

    /** Saisie des notes : contrôle préalable (un enseignant ou le secrétariat)... */
    public const SAISIE_PREALABLE = "is_granted('ROLE_ENSEIGNANT') or is_granted('ROLE_SECRETARIAT')";
    /** ... puis sur l'objet : le secrétariat/directeur partout, un enseignant seulement sur SES matières de classe (objet = Evaluation). */
    public const SAISIE_EVALUATION = "is_granted('ROLE_SECRETARIAT') or (is_granted('ROLE_ENSEIGNANT') and user.getEnseignant() !== null and user.getEnseignant() === object.getClasseMatiere().getEnseignant())";
    /** Idem pour une note (objet = Note). */
    public const SAISIE_NOTE = "is_granted('ROLE_SECRETARIAT') or (is_granted('ROLE_ENSEIGNANT') and user.getEnseignant() !== null and user.getEnseignant() === object.getEvaluation().getClasseMatiere().getEnseignant())";
    /** Données personnelles (élèves mineurs, tuteurs, enseignants) : réservées au personnel, pas aux comptes tuteur/élève. */
    public const PERSONNEL = "is_granted('ROLE_SECRETARIAT') or is_granted('ROLE_COMPTABLE') or is_granted('ROLE_ENSEIGNANT')";

    private function __construct()
    {
    }
}
