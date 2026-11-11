<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Moyenne figée d'un élève dans une matière pour un trimestre fermé (libellé et coefficient d'alors). */
#[ORM\Entity]
#[ORM\Table(name: 'resultats_matiere')]
#[ORM\UniqueConstraint(name: 'uniq_resultats_matiere_resultat_matiere', columns: ['resultat_periode_id', 'matiere_id'])]
class ResultatMatiere
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ResultatPeriode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ResultatPeriode $resultatPeriode;

    #[ORM\ManyToOne(targetEntity: Matiere::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Matiere $matiere;

    #[ORM\Column(length: 100)]
    private string $matiereLibelle;

    #[ORM\Column]
    private float $coefficient;

    #[ORM\Column(nullable: true)]
    private ?float $moyenne;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $rang;

    public function __construct(ResultatPeriode $resultatPeriode, Matiere $matiere, string $matiereLibelle, float $coefficient, ?float $moyenne, ?int $rang)
    {
        $this->resultatPeriode = $resultatPeriode;
        $this->matiere = $matiere;
        $this->matiereLibelle = $matiereLibelle;
        $this->coefficient = $coefficient;
        $this->moyenne = $moyenne;
        $this->rang = $rang;
    }

    public function getResultatPeriode(): ResultatPeriode
    {
        return $this->resultatPeriode;
    }

    public function getMatiere(): Matiere
    {
        return $this->matiere;
    }

    public function getMatiereLibelle(): string
    {
        return $this->matiereLibelle;
    }

    public function getCoefficient(): float
    {
        return $this->coefficient;
    }

    public function getMoyenne(): ?float
    {
        return $this->moyenne;
    }

    public function getRang(): ?int
    {
        return $this->rang;
    }
}
