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
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Un trimestre d'une année scolaire (numéro 1 à 3). */
#[ORM\Entity]
#[ORM\Table(name: 'periodes')]
#[ORM\UniqueConstraint(name: 'uniq_periodes_annee_numero', columns: ['annee_id', 'numero'])]
#[UniqueEntity(fields: ['annee', 'numero'], errorPath: 'numero', message: 'Ce trimestre existe déjà pour cette année.')]
#[ApiResource(
    shortName: 'Periode',
    operations: [
        new GetCollection(uriTemplate: '/periodes'),
        new Get(uriTemplate: '/periodes/{id}'),
        new Post(uriTemplate: '/periodes', security: Acces::ECRITURE),
        new Patch(uriTemplate: '/periodes/{id}', security: Acces::ECRITURE.' and object.estOuverte()'),
        new Delete(uriTemplate: '/periodes/{id}', security: Acces::ECRITURE.' and object.estOuverte()'),
    ],
    security: Acces::LECTURE,
    order: ['dateDebut' => 'ASC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['annee' => 'exact'])]
class Periode
{
    public const NUMERO_MAX = 3;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?AnneeScolaire $annee = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: false)]
    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: self::NUMERO_MAX, notInRangeMessage: 'Le numéro de trimestre doit être compris entre {{ min }} et {{ max }}.')]
    private ?int $numero = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $libelle = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: false)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: false)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dateFin = null;

    /** Passe à « fermee » par la fermeture du trimestre (POST /api/periodes/{id}/fermer), jamais par un PATCH. */
    #[ORM\Column(length: 20, enumType: StatutPeriode::class)]
    private StatutPeriode $statut = StatutPeriode::Ouverte;

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

    public function getNumero(): ?int
    {
        return $this->numero;
    }

    public function setNumero(?int $numero): void
    {
        $this->numero = $numero;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): void
    {
        $this->dateDebut = $dateDebut;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): void
    {
        $this->dateFin = $dateFin;
    }

    public function getStatut(): StatutPeriode
    {
        return $this->statut;
    }

    public function estOuverte(): bool
    {
        return StatutPeriode::Ouverte === $this->statut;
    }

    public function fermer(): void
    {
        $this->statut = StatutPeriode::Fermee;
    }

    public function rouvrir(): void
    {
        $this->statut = StatutPeriode::Ouverte;
    }

    #[Assert\Callback]
    public function validerDates(ExecutionContextInterface $context): void
    {
        if (null === $this->id && null !== $this->annee && !$this->annee->estModifiable()) {
            $context->buildViolation('Cette année scolaire est clôturée : plus de nouveau trimestre.')->atPath('annee')->addViolation();
        }

        if (null === $this->dateDebut || null === $this->dateFin) {
            return;
        }

        if ($this->dateFin <= $this->dateDebut) {
            $context->buildViolation('La date de fin doit suivre la date de début.')->atPath('dateFin')->addViolation();
        }

        $annee = $this->annee;
        if (null !== $annee && null !== $annee->getDateDebut() && null !== $annee->getDateFin()
            && ($this->dateDebut < $annee->getDateDebut() || $this->dateFin > $annee->getDateFin())) {
            $context->buildViolation('Les dates doivent être comprises dans l\'année scolaire.')->atPath('dateDebut')->addViolation();
        }
    }
}
