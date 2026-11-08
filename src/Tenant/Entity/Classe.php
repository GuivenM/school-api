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
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** Une classe (ex. « 6e A ») pour une année scolaire donnée, avec son enseignant titulaire. */
#[ORM\Entity]
#[ORM\Table(name: 'classes')]
#[ORM\UniqueConstraint(name: 'uniq_classes_annee_libelle', columns: ['annee_id', 'libelle'])]
#[UniqueEntity(fields: ['annee', 'libelle'], errorPath: 'libelle', message: 'Cette classe existe déjà pour cette année.')]
#[ApiResource(
    shortName: 'Classe',
    operations: [
        new GetCollection(uriTemplate: '/classes'),
        new Get(uriTemplate: '/classes/{id}'),
        new Post(uriTemplate: '/classes', security: Acces::ECRITURE),
        new Patch(uriTemplate: '/classes/{id}', security: Acces::ECRITURE),
        new Delete(uriTemplate: '/classes/{id}', security: Acces::ECRITURE),
    ],
    security: Acces::LECTURE,
    order: ['libelle' => 'ASC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['annee' => 'exact', 'niveau' => 'exact', 'titulaire' => 'exact'])]
class Classe
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?AnneeScolaire $annee = null;

    #[ORM\ManyToOne(targetEntity: Niveau::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Niveau $niveau = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private string $libelle = '';

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $effectifMax = null;

    #[ORM\ManyToOne(targetEntity: Enseignant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Enseignant $titulaire = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnnee(): ?AnneeScolaire
    {
        return $this->annee;
    }

    public function setAnnee(?AnneeScolaire $annee): void
    {
        $this->annee = $annee;
    }

    public function getNiveau(): ?Niveau
    {
        return $this->niveau;
    }

    public function setNiveau(?Niveau $niveau): void
    {
        $this->niveau = $niveau;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getEffectifMax(): ?int
    {
        return $this->effectifMax;
    }

    public function setEffectifMax(?int $effectifMax): void
    {
        $this->effectifMax = $effectifMax;
    }

    public function getTitulaire(): ?Enseignant
    {
        return $this->titulaire;
    }

    public function setTitulaire(?Enseignant $titulaire): void
    {
        $this->titulaire = $titulaire;
    }
}
