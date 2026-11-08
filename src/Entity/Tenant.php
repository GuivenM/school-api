<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une école = un tenant = une base de données dédiée (base centrale / landlord).
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenants')]
#[ORM\UniqueConstraint(name: 'uniq_tenants_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_tenants_db_name', columns: ['db_name'])]
class Tenant
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 28)]
    private string $slug;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $dbName;

    #[ORM\Column(length: 32)]
    private string $dbUser;

    /** Mot de passe de l'utilisateur MySQL de l'école, chiffré (TenantCipher). */
    #[ORM\Column(type: Types::TEXT)]
    private string $dbPasswordEnc;

    #[ORM\Column(length: 20, enumType: TenantStatus::class)]
    private TenantStatus $status = TenantStatus::Provisioning;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    public function __construct(string $slug, string $name, string $dbName, string $dbUser, string $dbPasswordEnc)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->dbName = $dbName;
        $this->dbUser = $dbUser;
        $this->dbPasswordEnc = $dbPasswordEnc;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDbName(): string
    {
        return $this->dbName;
    }

    public function getDbUser(): string
    {
        return $this->dbUser;
    }

    public function getDbPasswordEnc(): string
    {
        return $this->dbPasswordEnc;
    }

    public function getStatus(): TenantStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function activate(): void
    {
        $this->status = TenantStatus::Active;
        $this->activatedAt ??= new \DateTimeImmutable();
    }

    public function suspend(): void
    {
        $this->status = TenantStatus::Suspended;
    }
}
