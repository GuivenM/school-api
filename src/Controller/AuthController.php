<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AuthController
{
    /**
     * POST {"email": "...", "password": "..."}
     *  - /api/login   : utilisateur d'une école (en-tête X-Tenant: <slug> obligatoire)
     *  - /admin/login : super-admin de la plateforme
     * La réponse est produite par le firewall (json_login) : cette méthode n'est jamais exécutée.
     */
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    #[Route('/admin/login', name: 'admin_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Cette route est interceptée par le firewall (json_login).');
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] UserInterface $user, TenantContext $context): JsonResponse
    {
        return new JsonResponse([
            'tenant' => $context->getSlug(),
            'email' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);
    }
}
