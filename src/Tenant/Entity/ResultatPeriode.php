<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Résultat figé d'un élève pour un trimestre fermé (bulletin). Lecture via /api/periodes/{id}/resultats et
 * /api/inscriptions/{id}/bulletin : modifier ensuite un coefficient ou une note ne change pas un bulletin émis.
 */
#[ORM\Entity]
#[ORM\Table(name: 'resultats_periode')]
#[ORM\UniqueConstraint(name: 'uniq_resultats_periode_inscription_periode', columns: ['inscription_id', 'periode_id'])]
class ResultatPeriode
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Inscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Inscription $inscription;

    #[ORM\ManyToOne(targetEntity: Periode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Periode $periode;

    #[ORM\Column(nullable: true)]
    private ?float $moyenne;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $rang;

    /** Nombre d'élèves classés (ayant une moyenne) dans la classe pour ce trimestre. */
    #[ORM\Column(type: 'smallint')]
    private int $effectif;

    public function __construct(Inscription $inscription, Periode $periode, ?float $moyenne, ?int $rang, int $effectif)
    {
        $this->inscription = $inscription;
        $this->periode = $periode;
        $this->moyenne = $moyenne;
        $this->rang = $rang;
        $this->effectif = $effectif;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInscription(): Inscription
    {
        return $this->inscription;
    }

    public function getPeriode(): Periode
    {
        return $this->periode;
    }

    public function getMoyenne(): ?float
    {
        return $this->moyenne;
    }

    public function getRang(): ?int
    {
        return $this->rang;
    }

    public function getEffectif(): int
    {
        return $this->effectif;
    }
}
