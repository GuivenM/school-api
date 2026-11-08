<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

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

/** Une matière du catalogue de l'école. Coefficients et enseignants sont portés par ClasseMatiere (par classe et par année). */
#[ORM\Entity]
#[ORM\Table(name: 'matieres')]
#[ORM\UniqueConstraint(name: 'uniq_matieres_code', columns: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'Ce code de matière existe déjà.')]
#[ApiResource(
    shortName: 'Matiere',
    operations: [
        new GetCollection(uriTemplate: '/matieres'),
        new Get(uriTemplate: '/matieres/{id}'),
        new Post(uriTemplate: '/matieres', security: Acces::ECRITURE),
        new Patch(uriTemplate: '/matieres/{id}', security: Acces::ECRITURE),
        new Delete(uriTemplate: '/matieres/{id}', security: Acces::ECRITURE),
    ],
    security: Acces::LECTURE,
    order: ['libelle' => 'ASC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
class Matiere
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $code = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $libelle = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }
}
