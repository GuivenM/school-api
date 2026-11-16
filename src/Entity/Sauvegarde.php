<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une sauvegarde de la base d'une école (base centrale). Le fichier (SQL compressé puis chiffré) est copié sur chaque
 * destination configurée (local, distant) ; « destinations » liste celles où la copie a réussi.
 * La ligne survit à la suppression de l'école (tenant_slug garde son nom).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sauvegardes')]
class Sauvegarde
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $tenant;

    #[ORM\Column(length: 28)]
    private string $tenantSlug;

    #[ORM\Column(length: 30, enumType: TypeSauvegarde::class)]
    private TypeSauvegarde $type;

    /** Chemin relatif du fichier, identique sur toutes les destinations. */
    #[ORM\Column]
    private string $chemin;

    #[ORM\Column]
    private int $taille;

    #[ORM\Column(length: 64)]
    private string $sha256;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $destinations;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $destinationsEchouees;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $anneeLibelle;

    #[ORM\Column(nullable: true)]
    private ?string $note;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $destinations
     * @param list<string> $destinationsEchouees
     */
    public function __construct(Tenant $tenant, TypeSauvegarde $type, string $chemin, int $taille, string $sha256, array $destinations, array $destinationsEchouees, ?string $anneeLibelle, ?string $note)
    {
        $this->tenant = $tenant;
        $this->tenantSlug = $tenant->getSlug();
        $this->type = $type;
        $this->chemin = $chemin;
        $this->taille = $taille;
        $this->sha256 = $sha256;
        $this->destinations = $destinations;
        $this->destinationsEchouees = $destinationsEchouees;
        $this->anneeLibelle = $anneeLibelle;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }

    public function getType(): TypeSauvegarde
    {
        return $this->type;
    }

    public function getChemin(): string
    {
        return $this->chemin;
    }

    public function getTaille(): int
    {
        return $this->taille;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    /** @return list<string> */
    public function getDestinations(): array
    {
        return $this->destinations;
    }

    /** @return list<string> */
    public function getDestinationsEchouees(): array
    {
        return $this->destinationsEchouees;
    }

    public function getAnneeLibelle(): ?string
    {
        return $this->anneeLibelle;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @param list<string> $destinations */
    public function setDestinations(array $destinations): void
    {
        $this->destinations = $destinations;
    }
}
