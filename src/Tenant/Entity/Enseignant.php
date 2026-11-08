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
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Membre du corps enseignant. Titulaire de classe (Classe) et/ou enseignant d'une matière (ClasseMatiere). */
#[ORM\Entity]
#[ORM\Table(name: 'enseignants')]
#[ApiResource(
    shortName: 'Enseignant',
    operations: [
        new GetCollection(uriTemplate: '/enseignants'),
        new Get(uriTemplate: '/enseignants/{id}'),
        new Post(uriTemplate: '/enseignants', security: Acces::ECRITURE),
        new Patch(uriTemplate: '/enseignants/{id}', security: Acces::ECRITURE),
        new Delete(uriTemplate: '/enseignants/{id}', security: Acces::ECRITURE),
    ],
    security: Acces::PERSONNEL,
    order: ['nom' => 'ASC', 'prenoms' => 'ASC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['nom' => 'ipartial', 'prenoms' => 'ipartial', 'actif' => 'exact'])]
class Enseignant
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $nom = '';

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $prenoms = '';

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Regex(pattern: '/^\+?[0-9 .\-()]{6,25}$/', message: 'Numéro de téléphone invalide.')]
    private ?string $telephone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column]
    private bool $actif = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): void
    {
        $this->nom = $nom;
    }

    public function getPrenoms(): string
    {
        return $this->prenoms;
    }

    public function setPrenoms(string $prenoms): void
    {
        $this->prenoms = $prenoms;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): void
    {
        $this->telephone = $telephone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): void
    {
        $this->actif = $actif;
    }
}
