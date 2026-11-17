<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Appréciation d'un élève pour un trimestre : « générale » (conseil de classe, titulaire) quand la matière est vide, sinon celle de
 * l'enseignant de la matière. Écriture via PUT /api/inscriptions/{id}/appreciations/{periode}, tant que le trimestre est ouvert.
 */
#[ORM\Entity]
#[ORM\Table(name: 'appreciations')]
#[ORM\UniqueConstraint(name: 'uniq_appreciations_inscription_periode_matiere', columns: ['inscription_id', 'periode_id', 'matiere_id'])]
class Appreciation
{
    public const LONGUEUR_MAX = 500;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Inscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Inscription $inscription;

    #[ORM\ManyToOne(targetEntity: Periode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Periode $periode;

    #[ORM\ManyToOne(targetEntity: Matiere::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Matiere $matiere;

    #[ORM\Column(length: self::LONGUEUR_MAX)]
    private string $texte = '';

    #[ORM\Column(length: 180)]
    private string $auteur = '';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Inscription $inscription, Periode $periode, ?Matiere $matiere)
    {
        $this->inscription = $inscription;
        $this->periode = $periode;
        $this->matiere = $matiere;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getMatiere(): ?Matiere
    {
        return $this->matiere;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    public function getAuteur(): string
    {
        return $this->auteur;
    }

    public function modifier(string $texte, string $auteur): void
    {
        $this->texte = $texte;
        $this->auteur = $auteur;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
