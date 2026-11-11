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
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une évaluation (interrogation, devoir, composition) d'une matière d'une classe pour un trimestre. Notée sur 20.
 * Un enseignant ne gère que les évaluations de SES matières de classe ; le secrétariat et le directeur gèrent toutes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'evaluations')]
#[ApiResource(
    shortName: 'Evaluation',
    operations: [
        new GetCollection(uriTemplate: '/evaluations'),
        new Get(uriTemplate: '/evaluations/{id}'),
        new Post(uriTemplate: '/evaluations', security: Acces::SAISIE_PREALABLE, securityPostDenormalize: Acces::SAISIE_EVALUATION),
        new Patch(
            uriTemplate: '/evaluations/{id}',
            security: '('.Acces::SAISIE_EVALUATION.') and object.getPeriode().estOuverte()',
            securityPostDenormalize: Acces::SAISIE_EVALUATION,
        ),
        new Delete(uriTemplate: '/evaluations/{id}', security: '('.Acces::SAISIE_EVALUATION.') and object.getPeriode().estOuverte()'),
    ],
    security: Acces::PERSONNEL,
    order: ['date' => 'DESC', 'id' => 'DESC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['classeMatiere' => 'exact', 'periode' => 'exact', 'type' => 'exact'])]
class Evaluation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ClasseMatiere::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?ClasseMatiere $classeMatiere = null;

    #[ORM\ManyToOne(targetEntity: Periode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Periode $periode = null;

    #[ORM\Column(length: 20, enumType: TypeEvaluation::class, nullable: false)]
    #[Assert\NotNull]
    private ?TypeEvaluation $type = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $libelle = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: false)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

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

    public function getClasseMatiere(): ?ClasseMatiere
    {
        return $this->classeMatiere;
    }

    public function setClasseMatiere(?ClasseMatiere $classeMatiere): void
    {
        $this->classeMatiere = $classeMatiere;
    }

    public function getPeriode(): ?Periode
    {
        return $this->periode;
    }

    public function setPeriode(?Periode $periode): void
    {
        $this->periode = $periode;
    }

    public function getType(): ?TypeEvaluation
    {
        return $this->type;
    }

    public function setType(?TypeEvaluation $type): void
    {
        $this->type = $type;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): void
    {
        $this->date = $date;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[Assert\Callback]
    public function validerCoherence(ExecutionContextInterface $context): void
    {
        $periode = $this->periode;
        if (null === $periode) {
            return;
        }

        $classe = $this->classeMatiere?->getClasse();
        if (null !== $classe && $classe->getAnnee() !== $periode->getAnnee()) {
            $context->buildViolation('Ce trimestre n\'appartient pas à l\'année scolaire de la classe.')->atPath('periode')->addViolation();
        }

        if (null === $this->id && !$periode->estOuverte()) {
            $context->buildViolation('Ce trimestre est fermé : plus d\'évaluation possible.')->atPath('periode')->addViolation();
        }

        if (null !== $this->date && null !== $periode->getDateDebut() && null !== $periode->getDateFin()
            && ($this->date < $periode->getDateDebut() || $this->date > $periode->getDateFin())) {
            $context->buildViolation('La date doit être comprise dans le trimestre.')->atPath('date')->addViolation();
        }
    }
}
