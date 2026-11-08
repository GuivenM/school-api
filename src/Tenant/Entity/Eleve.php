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
 * L'élève en tant que personne (permanent) : son parcours année par année sera porté par l'inscription (lot 4).
 * Le matricule sera généré automatiquement au lot 4 ; en attendant il peut être fourni (reprise de données).
 */
#[ORM\Entity]
#[ORM\Table(name: 'eleves')]
#[ORM\UniqueConstraint(name: 'uniq_eleves_matricule', columns: ['matricule'])]
#[UniqueEntity(fields: ['matricule'], message: 'Ce matricule existe déjà.', ignoreNull: true)]
#[UniqueEntity(fields: ['nom', 'prenoms', 'dateNaissance'], errorPath: 'nom', message: 'Un élève avec ces nom, prénoms et date de naissance existe déjà.')]
#[ApiResource(
    shortName: 'Eleve',
    operations: [
        new GetCollection(uriTemplate: '/eleves'),
        new Get(uriTemplate: '/eleves/{id}'),
        new Post(uriTemplate: '/eleves', security: Acces::ECRITURE),
        new Patch(uriTemplate: '/eleves/{id}', security: Acces::ECRITURE),
        new Delete(uriTemplate: '/eleves/{id}', security: Acces::ECRITURE),
    ],
    security: Acces::PERSONNEL,
    order: ['nom' => 'ASC', 'prenoms' => 'ASC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['nom' => 'ipartial', 'prenoms' => 'ipartial', 'matricule' => 'exact', 'statut' => 'exact'])]
class Eleve
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $matricule = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $nom = '';

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $prenoms = '';

    #[ORM\Column(length: 1, enumType: Sexe::class, nullable: false)]
    #[Assert\NotNull]
    private ?Sexe $sexe = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: false)]
    #[Assert\NotNull]
    #[Assert\LessThan('today', message: 'La date de naissance doit être dans le passé.')]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $lieuNaissance = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $adresse = null;

    #[ORM\Column(length: 20, enumType: StatutEleve::class)]
    private StatutEleve $statut = StatutEleve::Actif;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMatricule(): ?string
    {
        return $this->matricule;
    }

    public function setMatricule(?string $matricule): void
    {
        $this->matricule = '' === $matricule ? null : $matricule;
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

    public function getSexe(): ?Sexe
    {
        return $this->sexe;
    }

    public function setSexe(?Sexe $sexe): void
    {
        $this->sexe = $sexe;
    }

    public function getDateNaissance(): ?\DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): void
    {
        $this->dateNaissance = $dateNaissance;
    }

    public function getLieuNaissance(): ?string
    {
        return $this->lieuNaissance;
    }

    public function setLieuNaissance(?string $lieuNaissance): void
    {
        $this->lieuNaissance = $lieuNaissance;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): void
    {
        $this->adresse = $adresse;
    }

    public function getStatut(): StatutEleve
    {
        return $this->statut;
    }

    public function setStatut(StatutEleve $statut): void
    {
        $this->statut = $statut;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
