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
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Matière enseignée dans une classe, avec son coefficient (par classe, donc par année : modifier un coefficient
 * en 2027 ne change pas les bulletins de 2026). L'enseignant sera ajouté au lot 3.
 */
#[ORM\Entity]
#[ORM\Table(name: 'classe_matieres')]
#[ORM\UniqueConstraint(name: 'uniq_classe_matieres_classe_matiere', columns: ['classe_id', 'matiere_id'])]
#[UniqueEntity(fields: ['classe', 'matiere'], errorPath: 'matiere', message: 'Cette matière est déjà associée à cette classe.')]
#[ApiResource(
    shortName: 'ClasseMatiere',
    operations: [
        new GetCollection(uriTemplate: '/classe_matieres'),
        new Get(uriTemplate: '/classe_matieres/{id}'),
        new Post(uriTemplate: '/classe_matieres', security: Acces::ECRITURE),
        new Patch(uriTemplate: '/classe_matieres/{id}', security: Acces::ECRITURE),
        new Delete(uriTemplate: '/classe_matieres/{id}', security: Acces::ECRITURE),
    ],
    security: Acces::LECTURE,
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['classe' => 'exact', 'matiere' => 'exact'])]
class ClasseMatiere
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Classe::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Classe $classe = null;

    #[ORM\ManyToOne(targetEntity: Matiere::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Matiere $matiere = null;

    #[ORM\Column(type: Types::FLOAT)]
    #[Assert\Positive(message: 'Le coefficient doit être supérieur à 0.')]
    #[Assert\LessThanOrEqual(value: 20, message: 'Le coefficient ne peut pas dépasser 20.')]
    private float $coefficient = 1.0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClasse(): ?Classe
    {
        return $this->classe;
    }

    public function setClasse(?Classe $classe): void
    {
        $this->classe = $classe;
    }

    public function getMatiere(): ?Matiere
    {
        return $this->matiere;
    }

    public function setMatiere(?Matiere $matiere): void
    {
        $this->matiere = $matiere;
    }

    public function getCoefficient(): float
    {
        return $this->coefficient;
    }

    public function setCoefficient(float $coefficient): void
    {
        $this->coefficient = $coefficient;
    }
}
