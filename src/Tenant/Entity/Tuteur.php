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

/** Parent ou tuteur légal. Rattaché aux élèves par EleveTuteur. */
#[ORM\Entity]
#[ORM\Table(name: 'tuteurs')]
#[ApiResource(
    shortName: 'Tuteur',
    operations: [
        new GetCollection(uriTemplate: '/tuteurs'),
        new Get(uriTemplate: '/tuteurs/{id}'),
        new Post(uriTemplate: '/tuteurs', security: Acces::ECRITURE),
        new Patch(uriTemplate: '/tuteurs/{id}', security: Acces::ECRITURE),
        new Delete(uriTemplate: '/tuteurs/{id}', security: Acces::ECRITURE),
    ],
    security: Acces::PERSONNEL,
    order: ['nom' => 'ASC', 'prenoms' => 'ASC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['nom' => 'ipartial', 'prenoms' => 'ipartial', 'telephone' => 'partial'])]
class Tuteur
{
    private const TELEPHONE = '/^\+?[0-9 .\-()]{6,25}$/';

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

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: self::TELEPHONE, message: 'Numéro de téléphone invalide.')]
    private string $telephone = '';

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Regex(pattern: self::TELEPHONE, message: 'Numéro de téléphone invalide.')]
    private ?string $telephone2 = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $profession = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $adresse = null;

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

    public function getTelephone(): string
    {
        return $this->telephone;
    }

    public function setTelephone(string $telephone): void
    {
        $this->telephone = $telephone;
    }

    public function getTelephone2(): ?string
    {
        return $this->telephone2;
    }

    public function setTelephone2(?string $telephone2): void
    {
        $this->telephone2 = $telephone2;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getProfession(): ?string
    {
        return $this->profession;
    }

    public function setProfession(?string $profession): void
    {
        $this->profession = $profession;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): void
    {
        $this->adresse = $adresse;
    }
}
