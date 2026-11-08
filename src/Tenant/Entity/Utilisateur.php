<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Utilisateur d'une école (présent dans CHAQUE base d'école). Le lien vers enseignant/tuteur/élève viendra au lot 3. */
#[ORM\Entity]
#[ORM\Table(name: 'utilisateurs')]
#[ORM\UniqueConstraint(name: 'uniq_utilisateurs_email', columns: ['email'])]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 20, enumType: RoleUtilisateur::class)]
    private RoleUtilisateur $role;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nom = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $prenoms = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, RoleUtilisateur $role)
    {
        $this->email = mb_strtolower($email);
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRole(): RoleUtilisateur
    {
        return $this->role;
    }

    public function getRoles(): array
    {
        return [$this->role->securityRole(), 'ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): void
    {
        $this->actif = $actif;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function getPrenoms(): ?string
    {
        return $this->prenoms;
    }

    public function setIdentite(?string $nom, ?string $prenoms): void
    {
        $this->nom = $nom;
        $this->prenoms = $prenoms;
    }

    public function eraseCredentials(): void
    {
    }
}
