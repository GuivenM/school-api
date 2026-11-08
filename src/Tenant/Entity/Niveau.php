<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiPlatform\Acces;
use App\ApiPlatform\DbExceptionMap;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Niveau d'études (6e, 5e...). « niveauSuivant » indique où vont les élèves admis ; vide = dernier niveau. */
#[ORM\Entity]
#[ORM\Table(name: 'niveaux')]
#[ORM\UniqueConstraint(name: 'uniq_niveaux_code', columns: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'Ce code de niveau existe déjà.')]
#[ApiResource(
    shortName: 'Niveau',
    operations: [
        new GetCollection(uriTemplate: '/niveaux'),
        new Get(uriTemplate: '/niveaux/{id}'),
        new Post(uriTemplate: '/niveaux', security: Acces::ECRITURE),
        new Patch(uriTemplate: '/niveaux/{id}', security: Acces::ECRITURE),
        new Delete(uriTemplate: '/niveaux/{id}', security: Acces::ECRITURE),
    ],
    security: Acces::LECTURE,
    order: ['ordre' => 'ASC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
class Niveau
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $code = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $libelle = '';

    #[ORM\Column(nullable: false)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private ?int $ordre = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $niveauSuivant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getOrdre(): ?int
    {
        return $this->ordre;
    }

    public function setOrdre(?int $ordre): void
    {
        $this->ordre = $ordre;
    }

    public function getNiveauSuivant(): ?self
    {
        return $this->niveauSuivant;
    }

    public function setNiveauSuivant(?self $niveauSuivant): void
    {
        $this->niveauSuivant = $niveauSuivant;
    }

    #[Assert\Callback]
    public function validerNiveauSuivant(ExecutionContextInterface $context): void
    {
        if ($this->niveauSuivant === $this) {
            $context->buildViolation('Un niveau ne peut pas être son propre niveau suivant.')->atPath('niveauSuivant')->addViolation();
        }
    }
}
