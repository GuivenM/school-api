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
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Note d'un élève (via son inscription) à une évaluation, sur 20. « valeur » n'est renseignée que pour le statut « saisie » ;
 * absent / dispense / non_evalue n'entrent pas dans les moyennes. Pour saisir toute une classe d'un coup :
 * POST /api/evaluations/{id}/notes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notes')]
#[ORM\UniqueConstraint(name: 'uniq_notes_evaluation_inscription', columns: ['evaluation_id', 'inscription_id'])]
#[UniqueEntity(fields: ['evaluation', 'inscription'], errorPath: 'inscription', message: 'Cet élève a déjà une note pour cette évaluation.')]
#[ApiResource(
    shortName: 'Note',
    operations: [
        new GetCollection(uriTemplate: '/notes'),
        new Get(uriTemplate: '/notes/{id}'),
        new Post(uriTemplate: '/notes', security: Acces::SAISIE_PREALABLE, securityPostDenormalize: Acces::SAISIE_NOTE),
        new Patch(
            uriTemplate: '/notes/{id}',
            security: '('.Acces::SAISIE_NOTE.') and object.getEvaluation().getPeriode().estOuverte()',
            securityPostDenormalize: Acces::SAISIE_NOTE,
        ),
        new Delete(uriTemplate: '/notes/{id}', security: '('.Acces::SAISIE_NOTE.') and object.getEvaluation().getPeriode().estOuverte()'),
    ],
    security: Acces::PERSONNEL,
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['evaluation' => 'exact', 'inscription' => 'exact', 'statut' => 'exact'])]
class Note
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Evaluation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Evaluation $evaluation = null;

    #[ORM\ManyToOne(targetEntity: Inscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Inscription $inscription = null;

    #[ORM\Column(nullable: true)]
    private ?float $valeur = null;

    #[ORM\Column(length: 20, enumType: StatutNote::class)]
    private StatutNote $statut = StatutNote::Saisie;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvaluation(): ?Evaluation
    {
        return $this->evaluation;
    }

    public function setEvaluation(?Evaluation $evaluation): void
    {
        $this->evaluation = $evaluation;
    }

    public function getInscription(): ?Inscription
    {
        return $this->inscription;
    }

    public function setInscription(?Inscription $inscription): void
    {
        $this->inscription = $inscription;
    }

    public function getValeur(): ?float
    {
        return $this->valeur;
    }

    public function setValeur(?float $valeur): void
    {
        $this->valeur = $valeur;
    }

    public function getStatut(): StatutNote
    {
        return $this->statut;
    }

    public function setStatut(StatutNote $statut): void
    {
        $this->statut = $statut;
    }

    #[Assert\Callback]
    public function validerCoherence(ExecutionContextInterface $context): void
    {
        if (StatutNote::Saisie === $this->statut) {
            if (null === $this->valeur) {
                $context->buildViolation('Une note « saisie » doit avoir une valeur.')->atPath('valeur')->addViolation();
            } elseif ($this->valeur < 0 || $this->valeur > 20) {
                $context->buildViolation('La note doit être comprise entre 0 et 20.')->atPath('valeur')->addViolation();
            } elseif (round($this->valeur, 2) !== $this->valeur) {
                $context->buildViolation('La note ne peut pas avoir plus de deux décimales.')->atPath('valeur')->addViolation();
            }
        } elseif (null !== $this->valeur) {
            $context->buildViolation('Une note « absent », « dispense » ou « non_evalue » n\'a pas de valeur.')->atPath('valeur')->addViolation();
        }

        $evaluation = $this->evaluation;
        $inscription = $this->inscription;
        if (null === $evaluation || null === $inscription) {
            return;
        }

        if ($inscription->getClasse() !== $evaluation->getClasseMatiere()?->getClasse()) {
            $context->buildViolation('Cet élève n\'est pas inscrit dans la classe de cette évaluation.')->atPath('inscription')->addViolation();
        }
        if (null === $this->id && StatutInscription::EnCours !== $inscription->getStatut()) {
            $context->buildViolation('Seul un élève dont l\'inscription est « en_cours » peut recevoir une nouvelle note.')->atPath('inscription')->addViolation();
        }
        if (null !== $evaluation->getPeriode() && !$evaluation->getPeriode()->estOuverte()) {
            $context->buildViolation('Ce trimestre est fermé : les notes ne peuvent plus être modifiées.')->atPath('evaluation')->addViolation();
        }
    }
}
