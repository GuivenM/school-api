<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paramètres propres à une école (présent dans CHAQUE base d'école).
 * Ex. : seuil_admission, bareme_defaut, nom_ecole.
 */
#[ORM\Entity]
#[ORM\Table(name: 'parametres')]
class Parametre
{
    #[ORM\Id]
    #[ORM\Column(length: 100)]
    private string $cle;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $valeur;

    public function __construct(string $cle, ?string $valeur)
    {
        $this->cle = $cle;
        $this->valeur = $valeur;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getValeur(): ?string
    {
        return $this->valeur;
    }

    public function setValeur(?string $valeur): void
    {
        $this->valeur = $valeur;
    }
}
