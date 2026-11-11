<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Compteurs atomiques (matricules). Incrémentés en SQL par MatriculeGenerator ; l'entité n'existe que pour que
 * Doctrine connaisse la table (sinon un futur « migrations:diff » proposerait de la supprimer).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sequences')]
class Sequence
{
    #[ORM\Id]
    #[ORM\Column(length: 50)]
    private string $cle;

    #[ORM\Column]
    private int $valeur = 0;

    public function __construct(string $cle)
    {
        $this->cle = $cle;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getValeur(): int
    {
        return $this->valeur;
    }
}
