<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiPlatform\Acces;
use App\ApiPlatform\DbExceptionMap;
use App\Validator\UnSeulTuteurPrincipal;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** Lien entre un élève et un tuteur (un élève peut avoir plusieurs tuteurs, un tuteur plusieurs élèves). */
#[ORM\Entity]
#[ORM\Table(name: 'eleve_tuteurs')]
#[ORM\UniqueConstraint(name: 'uniq_eleve_tuteurs_eleve_tuteur', columns: ['eleve_id', 'tuteur_id'])]
#[UniqueEntity(fields: ['eleve', 'tuteur'], errorPath: 'tuteur', message: 'Ce tuteur est déjà lié à cet élève.')]
#[UnSeulTuteurPrincipal]
#[ApiResource(
    shortName: 'EleveTuteur',
    operations: [
        new GetCollection(uriTemplate: '/eleve_tuteurs'),
        new Get(uriTemplate: '/eleve_tuteurs/{id}'),
        new Post(uriTemplate: '/eleve_tuteurs', security: Acces::ECRITURE),
        new Patch(uriTemplate: '/eleve_tuteurs/{id}', security: Acces::ECRITURE),
        new Delete(uriTemplate: '/eleve_tuteurs/{id}', security: Acces::ECRITURE),
    ],
    security: Acces::PERSONNEL,
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['eleve' => 'exact', 'tuteur' => 'exact'])]
class EleveTuteur
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Eleve::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Eleve $eleve = null;

    #[ORM\ManyToOne(targetEntity: Tuteur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Tuteur $tuteur = null;

    #[ORM\Column(length: 20, enumType: LienParente::class, nullable: false)]
    #[Assert\NotNull]
    private ?LienParente $lien = null;

    #[ORM\Column]
    private bool $principal = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEleve(): ?Eleve
    {
        return $this->eleve;
    }

    public function setEleve(?Eleve $eleve): void
    {
        $this->eleve = $eleve;
    }

    public function getTuteur(): ?Tuteur
    {
        return $this->tuteur;
    }

    public function setTuteur(?Tuteur $tuteur): void
    {
        $this->tuteur = $tuteur;
    }

    public function getLien(): ?LienParente
    {
        return $this->lien;
    }

    public function setLien(?LienParente $lien): void
    {
        $this->lien = $lien;
    }

    public function isPrincipal(): bool
    {
        return $this->principal;
    }

    public function setPrincipal(bool $principal): void
    {
        $this->principal = $principal;
    }
}
