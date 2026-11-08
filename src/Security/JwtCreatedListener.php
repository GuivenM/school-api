<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\SuperAdmin;
use App\Tenancy\TenantContext;
use App\Tenant\Entity\Utilisateur;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ajoute au JWT sa portée : "landlord" (super-admin) ou "tenant" + slug de l'école.
 */
#[AsEventListener(event: Events::JWT_CREATED)]
final class JwtCreatedListener
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function __invoke(JWTCreatedEvent $event): void
    {
        $payload = $event->getData();
        $user = $event->getUser();

        if ($user instanceof SuperAdmin) {
            $payload['scope'] = 'landlord';
        } elseif ($user instanceof Utilisateur) {
            $slug = $this->context->getSlug();
            if (null === $slug) {
                throw new \LogicException('JWT d\'utilisateur d\'école demandé sans école active.');
            }
            $payload['scope'] = 'tenant';
            $payload['tenant'] = $slug;
        } else {
            throw new \LogicException('Type d\'utilisateur inattendu pour un JWT.');
        }

        $event->setData($payload);
    }
}
