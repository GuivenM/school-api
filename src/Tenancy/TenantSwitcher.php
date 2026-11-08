<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Change d'école : met à jour le contexte, vide l'EntityManager "tenant" et ferme la connexion
 * pour que la suivante s'ouvre sur la base de la bonne école.
 */
final class TenantSwitcher
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantCipher $cipher,
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function switchTo(Tenant $tenant): void
    {
        $this->context->set(new TenantCredentials(
            $tenant->getSlug(),
            $tenant->getDbName(),
            $tenant->getDbUser(),
            $this->cipher->decrypt($tenant->getDbPasswordEnc()),
        ));
        $this->resetTenantConnection();
    }

    public function clear(): void
    {
        if (!$this->context->isSet()) {
            return;
        }

        $this->context->clear();
        $this->resetTenantConnection();
    }

    private function resetTenantConnection(): void
    {
        $em = $this->registry->getManager('tenant');
        if ($em instanceof EntityManagerInterface && !$em->isOpen()) {
            $this->registry->resetManager('tenant');
        } else {
            $em->clear();
        }

        $this->registry->getConnection('tenant')->close();
    }
}
