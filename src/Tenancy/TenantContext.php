<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * École active pour la requête / la commande en cours.
 * Simple conteneur : ne dépend pas de Doctrine (évite une dépendance circulaire avec le middleware).
 * Pour changer d'école, passer par TenantSwitcher.
 */
final class TenantContext
{
    private ?TenantCredentials $credentials = null;

    public function set(TenantCredentials $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function clear(): void
    {
        $this->credentials = null;
    }

    public function get(): ?TenantCredentials
    {
        return $this->credentials;
    }

    public function isSet(): bool
    {
        return null !== $this->credentials;
    }

    public function getSlug(): ?string
    {
        return $this->credentials?->slug;
    }
}
