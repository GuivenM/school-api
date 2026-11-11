<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenant\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Actions qu'un utilisateur d'école fait sur son propre compte, quel que soit son rôle. */
final class MonCompteController
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /** POST {"currentPassword": "...", "newPassword": "..."} */
    #[Route('/api/me/password', name: 'api_me_password', methods: ['POST'])]
    public function changePassword(Request $request, #[CurrentUser] Utilisateur $user): Response
    {
        $body = json_decode($request->getContent(), true);
        $current = \is_array($body) ? ($body['currentPassword'] ?? null) : null;
        $new = \is_array($body) ? ($body['newPassword'] ?? null) : null;

        if (!\is_string($current) || !\is_string($new)) {
            return new JsonResponse(['error' => 'currentPassword et newPassword sont requis.'], 400);
        }
        if (!$this->hasher->isPasswordValid($user, $current)) {
            return new JsonResponse(['error' => 'Mot de passe actuel incorrect.'], 422);
        }
        if (mb_strlen($new) < 10 || mb_strlen($new) > 4096) {
            return new JsonResponse(['error' => 'Le nouveau mot de passe doit contenir au moins 10 caractères.'], 422);
        }
        if ($new === $current) {
            return new JsonResponse(['error' => 'Le nouveau mot de passe doit être différent de l\'actuel.'], 422);
        }

        $user->setPassword($this->hasher->hashPassword($user, $new));
        $this->tenantEntityManager->flush();

        return new Response(null, 204);
    }
}
