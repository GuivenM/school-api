<?php

declare(strict_types=1);

namespace App\Security;

use App\Tenant\Entity\Utilisateur;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Refuse la connexion (login et JWT) aux comptes désactivés. */
final class UtilisateurChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof Utilisateur && !$user->isActif()) {
            throw new CustomUserMessageAccountStatusException('Compte désactivé.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
