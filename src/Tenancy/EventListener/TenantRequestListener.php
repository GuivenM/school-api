<?php

declare(strict_types=1);

namespace App\Tenancy\EventListener;

use App\Entity\Tenant;
use App\Entity\TenantStatus;
use App\Tenancy\TenantSwitcher;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Détermine l'école de la requête, AVANT le firewall :
 *  - /admin/*      : aucune école ;
 *  - /api/login    : en-tête X-Tenant obligatoire (l'utilisateur n'a pas encore de token) ;
 *  - /api/*        : l'école vient UNIQUEMENT du JWT (X-Tenant est ignoré) ;
 *  - /_tenant/*    : X-Tenant accepté en dev/test seulement (diagnostic et tests).
 * La validité du token (signature, expiration, portée) est vérifiée ensuite par le firewall.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class TenantRequestListener
{
    public function __construct(
        private readonly TenantSwitcher $switcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly JWTEncoderInterface $jwtEncoder,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (1 === preg_match('#^/admin(/|$)#', $path)) {
            $this->switcher->clear();

            return;
        }

        if ('/api/login' === $path) {
            $this->activateFromHeader($request, true);

            return;
        }

        if (1 === preg_match('#^/api(/|$)#', $path)) {
            $this->activateFromToken($request);

            return;
        }

        if (\in_array($this->environment, ['dev', 'test'], true) && str_starts_with($path, '/_tenant/')) {
            $this->activateFromHeader($request, false);

            return;
        }

        $this->switcher->clear();
    }

    private function activateFromHeader(Request $request, bool $required): void
    {
        $slug = $request->headers->get('X-Tenant');
        if (null === $slug || '' === $slug) {
            $this->switcher->clear();
            if ($required) {
                throw new BadRequestHttpException('En-tête X-Tenant requis.');
            }

            return;
        }

        $tenant = $this->findActiveTenant($slug);
        if (null === $tenant) {
            $this->switcher->clear();

            throw new NotFoundHttpException('École inconnue.');
        }

        $this->switcher->switchTo($tenant);
    }

    private function activateFromToken(Request $request): void
    {
        $this->switcher->clear();

        $header = (string) $request->headers->get('Authorization', '');
        if (1 !== preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return;
        }

        try {
            // L'encodeur vérifie signature et expiration, sans déclencher l'événement JWT_DECODED.
            $payload = $this->jwtEncoder->decode($matches[1]);
        } catch (\Throwable) {
            return; // token invalide : le firewall répondra 401
        }

        if (!\is_array($payload) || 'tenant' !== ($payload['scope'] ?? null) || !\is_string($payload['tenant'] ?? null)) {
            return;
        }

        $tenant = $this->findActiveTenant($payload['tenant']);
        if (null !== $tenant) {
            $this->switcher->switchTo($tenant);
        }
    }

    private function findActiveTenant(string $slug): ?Tenant
    {
        return $this->entityManager->getRepository(Tenant::class)
            ->findOneBy(['slug' => $slug, 'status' => TenantStatus::Active]);
    }
}
