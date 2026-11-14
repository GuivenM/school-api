<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiPlatform\Acces;
use App\ApiPlatform\DbExceptionMap;
use App\State\InscriptionProcessor;
use App\Validator\TypeInscriptionCoherent;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Parcours d'un élève pour UNE année scolaire : c'est l'inscription (et non l'élève) qui porte la classe, puis les notes,
 * les bulletins et la décision de fin d'année. Une inscription par élève et par année.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inscriptions')]
#[ORM\UniqueConstraint(name: 'uniq_inscriptions_eleve_annee', columns: ['eleve_id', 'annee_id'])]
#[UniqueEntity(fields: ['eleve', 'annee'], errorPath: 'eleve', message: 'Cet élève est déjà inscrit pour cette année.')]
#[TypeInscriptionCoherent]
#[ApiResource(
    shortName: 'Inscription',
    operations: [
        new GetCollection(uriTemplate: '/inscriptions'),
        new Get(uriTemplate: '/inscriptions/{id}'),
        new Post(uriTemplate: '/inscriptions', security: Acces::ECRITURE, processor: InscriptionProcessor::class),
        new Patch(uriTemplate: '/inscriptions/{id}', security: Acces::ECRITURE.' and object.getAnnee().estModifiable()', processor: InscriptionProcessor::class),
        new Delete(uriTemplate: '/inscriptions/{id}', security: Acces::ECRITURE.' and object.getAnnee().estModifiable()'),
    ],
    security: Acces::PERSONNEL,
    order: ['dateInscription' => 'DESC', 'id' => 'DESC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['eleve' => 'exact', 'annee' => 'exact', 'classe' => 'exact', 'statut' => 'exact', 'type' => 'exact'])]
class Inscription
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Eleve::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Eleve $eleve = null;

    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?AnneeScolaire $annee = null;

    #[ORM\ManyToOne(targetEntity: Classe::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Classe $classe = null;

    #[ORM\Column(length: 20, enumType: TypeInscription::class, nullable: false)]
    #[Assert\NotNull]
    private ?TypeInscription $type = null;

    #[ORM\Column(length: 20, enumType: StatutInscription::class)]
    private StatutInscription $statut = StatutInscription::EnCours;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: false)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dateInscription;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateSortie = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $motifSortie = null;

    /** École d'origine (nouvelle inscription ou transfert entrant). */
    #[ORM\Column(nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $provenance = null;

    /** Bilan figé à la clôture de l'année (lecture seule via l'API). */
    #[ORM\Column(nullable: true)]
    #[ApiProperty(writable: false)]
    private ?float $moyenneAnnuelle = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[ApiProperty(writable: false)]
    private ?int $rangAnnuel = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[ApiProperty(writable: false)]
    private ?int $effectifAnnuel = null;

    /** Décision calculée (moyenne annuelle ≥ seuil d'admission), enregistrée à la clôture. */
    #[ORM\Column(length: 20, enumType: DecisionFinAnnee::class, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?DecisionFinAnnee $decisionAuto = null;

    /** Avant la clôture : décision prise par le conseil de classe (POST /api/inscriptions/{id}/decision). Après : décision finale. */
    #[ORM\Column(length: 20, enumType: DecisionFinAnnee::class, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?DecisionFinAnnee $decision = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(writable: false)]
    private ?string $decisionMotif = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?string $decisionPar = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $decisionLe = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Avertissements calculés à l'enregistrement (ex. effectif maximum dépassé) : non enregistrés, jamais bloquants. */
    #[ApiProperty(writable: false)]
    private array $avertissements = [];

    public function __construct()
    {
        $this->dateInscription = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
    }

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

    public function getAnnee(): ?AnneeScolaire
    {
        return $this->annee;
    }

    public function setAnnee(?AnneeScolaire $annee): void
    {
        $this->annee = $annee;
    }

    public function getClasse(): ?Classe
    {
        return $this->classe;
    }

    public function setClasse(?Classe $classe): void
    {
        $this->classe = $classe;
    }

    public function getType(): ?TypeInscription
    {
        return $this->type;
    }

    public function setType(?TypeInscription $type): void
    {
        $this->type = $type;
    }

    public function getStatut(): StatutInscription
    {
        return $this->statut;
    }

    public function setStatut(StatutInscription $statut): void
    {
        $this->statut = $statut;
    }

    public function getDateInscription(): ?\DateTimeImmutable
    {
        return $this->dateInscription;
    }

    public function setDateInscription(?\DateTimeImmutable $dateInscription): void
    {
        $this->dateInscription = $dateInscription;
    }

    public function getDateSortie(): ?\DateTimeImmutable
    {
        return $this->dateSortie;
    }

    public function setDateSortie(?\DateTimeImmutable $dateSortie): void
    {
        $this->dateSortie = $dateSortie;
    }

    public function getMotifSortie(): ?string
    {
        return $this->motifSortie;
    }

    public function setMotifSortie(?string $motifSortie): void
    {
        $this->motifSortie = $motifSortie;
    }

    public function getProvenance(): ?string
    {
        return $this->provenance;
    }

    public function setProvenance(?string $provenance): void
    {
        $this->provenance = $provenance;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMoyenneAnnuelle(): ?float
    {
        return $this->moyenneAnnuelle;
    }

    public function getRangAnnuel(): ?int
    {
        return $this->rangAnnuel;
    }

    public function getEffectifAnnuel(): ?int
    {
        return $this->effectifAnnuel;
    }

    public function getDecisionAuto(): ?DecisionFinAnnee
    {
        return $this->decisionAuto;
    }

    public function getDecision(): ?DecisionFinAnnee
    {
        return $this->decision;
    }

    public function getDecisionMotif(): ?string
    {
        return $this->decisionMotif;
    }

    public function getDecisionPar(): ?string
    {
        return $this->decisionPar;
    }

    public function getDecisionLe(): ?\DateTimeImmutable
    {
        return $this->decisionLe;
    }

    /** Décision du conseil de classe (avant la clôture). */
    public function definirDecision(DecisionFinAnnee $decision, ?string $motif, string $par): void
    {
        $this->decision = $decision;
        $this->decisionMotif = $motif;
        $this->decisionPar = $par;
        $this->decisionLe = new \DateTimeImmutable();
    }

    public function effacerDecision(): void
    {
        $this->decision = null;
        $this->decisionMotif = null;
        $this->decisionPar = null;
        $this->decisionLe = null;
    }

    /** Fige le bilan de l'année (clôture) : l'inscription est « terminee ». */
    public function enregistrerBilan(?float $moyenne, ?int $rang, ?int $effectif, ?DecisionFinAnnee $auto, DecisionFinAnnee $finale): void
    {
        $this->moyenneAnnuelle = $moyenne;
        $this->rangAnnuel = $rang;
        $this->effectifAnnuel = $effectif;
        $this->decisionAuto = $auto;
        $this->decision = $finale;
        $this->statut = StatutInscription::Terminee;
    }

    /** @return list<string> */
    public function getAvertissements(): array
    {
        return $this->avertissements;
    }

    /** @param list<string> $avertissements */
    public function setAvertissements(array $avertissements): void
    {
        $this->avertissements = $avertissements;
    }

    #[Assert\Callback]
    public function validerCoherence(ExecutionContextInterface $context): void
    {
        if (null !== $this->classe && null !== $this->annee && $this->classe->getAnnee() !== $this->annee) {
            $context->buildViolation('Cette classe n\'appartient pas à l\'année scolaire choisie.')->atPath('classe')->addViolation();
        }

        if (null === $this->id) {
            if (StatutInscription::EnCours !== $this->statut) {
                $context->buildViolation('Une nouvelle inscription est « en_cours ».')->atPath('statut')->addViolation();
            }
            if (null !== $this->annee && !$this->annee->estModifiable()) {
                $context->buildViolation('Cette année scolaire est clôturée : plus d\'inscription possible.')->atPath('annee')->addViolation();
            }
            if (null !== $this->eleve && StatutEleve::Actif !== $this->eleve->getStatut()) {
                $context->buildViolation('Seul un élève « actif » peut être inscrit.')->atPath('eleve')->addViolation();
            }
        }

        if (StatutInscription::Terminee === $this->statut) {
            $context->buildViolation('Le statut « terminee » est réservé à la clôture d\'année.')->atPath('statut')->addViolation();
        }

        if (StatutInscription::EnCours !== $this->statut && StatutInscription::Terminee !== $this->statut) {
            if (null === $this->dateSortie) {
                $context->buildViolation('La date de sortie est requise.')->atPath('dateSortie')->addViolation();
            } elseif (null !== $this->dateInscription && $this->dateSortie < $this->dateInscription) {
                $context->buildViolation('La date de sortie ne peut pas précéder la date d\'inscription.')->atPath('dateSortie')->addViolation();
            }
        }
    }
}
