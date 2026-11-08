<?php

declare(strict_types=1);

namespace App\Tenancy\EventListener;

use App\Entity\Tenant;
use App\Entity\TenantStatus;
use App\Tenancy\TenantSwitcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Détermine l'école de la requête à partir de l'en-tête X-Tenant.
 * ATTENTION : provisoire (lot 1A). Au lot 1B, l'école viendra du JWT et l'en-tête ne sera
 * plus accepté que pour la route de login.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class TenantRequestListener
{
    public function __construct(
        private readonly TenantSwitcher $switcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $slug = $event->getRequest()->headers->get('X-Tenant');
        if (null === $slug || '' === $slug) {
            $this->switcher->clear();

            return;
        }

        $tenant = $this->entityManager->getRepository(Tenant::class)
            ->findOneBy(['slug' => $slug, 'status' => TenantStatus::Active]);
        if (null === $tenant) {
            $this->switcher->clear();

            throw new NotFoundHttpException('École inconnue.');
        }

        $this->switcher->switchTo($tenant);
    }
}
