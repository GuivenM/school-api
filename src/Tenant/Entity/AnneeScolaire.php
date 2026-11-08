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
use App\Validator\UneSeuleAnneeActive;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'annees_scolaires')]
#[ORM\UniqueConstraint(name: 'uniq_annees_scolaires_libelle', columns: ['libelle'])]
#[UniqueEntity(fields: ['libelle'], message: 'Cette année scolaire existe déjà.')]
#[UneSeuleAnneeActive]
#[ApiResource(
    shortName: 'AnneeScolaire',
    operations: [
        new GetCollection(uriTemplate: '/annees_scolaires'),
        new Get(uriTemplate: '/annees_scolaires/{id}'),
        new Post(uriTemplate: '/annees_scolaires', security: Acces::ECRITURE),
        new Patch(uriTemplate: '/annees_scolaires/{id}', security: Acces::ECRITURE.' and object.estModifiable()'),
        new Delete(uriTemplate: '/annees_scolaires/{id}', security: Acces::ECRITURE.' and object.estBrouillon()'),
    ],
    security: Acces::LECTURE,
    order: ['dateDebut' => 'DESC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact'])]
class AnneeScolaire
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{4}-\d{4}$/', message: 'Format attendu : 2026-2027.')]
    private string $libelle = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: false)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: false)]
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'dateDebut', message: 'La date de fin doit suivre la date de début.')]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(length: 20, enumType: StatutAnnee::class)]
    private StatutAnnee $statut = StatutAnnee::Brouillon;

    /** Renseignée par la clôture d'année (lot 6), jamais par l'API. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $clotureeLe = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getStatut(): StatutAnnee
    {
        return $this->statut;
    }

    public function setStatut(StatutAnnee $statut): void
    {
        $this->statut = $statut;
    }

    public function getClotureeLe(): ?\DateTimeImmutable
    {
        return $this->clotureeLe;
    }

    /** Modifiable via l'API tant que l'année n'est pas clôturée. */
    public function estModifiable(): bool
    {
        return \in_array($this->statut, [StatutAnnee::Brouillon, StatutAnnee::Active], true);
    }

    public function estBrouillon(): bool
    {
        return StatutAnnee::Brouillon === $this->statut;
    }

    #[Assert\Callback]
    public function validerCoherence(ExecutionContextInterface $context): void
    {
        if (!$this->estModifiable()) {
            $context->buildViolation('Les statuts « cloturee » et « archivee » sont réservés à la clôture d\'année.')
                ->atPath('statut')->addViolation();
        }

        if (1 === preg_match('/^(\d{4})-(\d{4})$/', $this->libelle, $m) && (int) $m[2] !== (int) $m[1] + 1) {
            $context->buildViolation('Les deux années doivent se suivre (ex. 2026-2027).')
                ->atPath('libelle')->addViolation();
        }
    }
}
