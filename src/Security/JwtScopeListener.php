<?php

declare(strict_types=1);

namespace App\Security;

use App\Tenancy\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Empêche qu'un token serve ailleurs que là où il a été émis :
 *  - /admin  : uniquement les tokens "landlord" ;
 *  - le reste : uniquement les tokens "tenant" dont l'école est l'école active de la requête.
 * (Sans ça, un utilisateur d'école de même email qu'un super-admin passerait sur /admin.)
 */
#[AsEventListener(event: Events::JWT_DECODED)]
final class JwtScopeListener
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TenantContext $context,
    ) {
    }

    public function __invoke(JWTDecodedEvent $event): void
    {
        $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
        $payload = $event->getPayload();
        $scope = $payload['scope'] ?? null;

        if (1 === preg_match('#^/admin(/|$)#', $path)) {
            if ('landlord' !== $scope) {
                $event->markAsInvalid();
            }

            return;
        }

        $active = $this->context->getSlug();
        if ('tenant' !== $scope || null === $active || ($payload['tenant'] ?? null) !== $active) {
            $event->markAsInvalid();
        }
    }
}
