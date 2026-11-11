<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiPlatform\Acces;
use App\ApiPlatform\DbExceptionMap;
use App\State\UtilisateurProcessor;
use App\Validator\UnDirecteurActifAuMoins;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Compte de connexion d'une école (présent dans CHAQUE base d'école), géré par le directeur.
 * Un compte « enseignant », « tuteur » ou « eleve » est lié à la personne correspondante.
 * Pas de suppression : un compte se désactive (actif = false), ce qui coupe aussi ses jetons en cours.
 */
#[ORM\Entity]
#[ORM\Table(name: 'utilisateurs')]
#[ORM\UniqueConstraint(name: 'uniq_utilisateurs_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_utilisateurs_enseignant', columns: ['enseignant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_utilisateurs_tuteur', columns: ['tuteur_id'])]
#[ORM\UniqueConstraint(name: 'uniq_utilisateurs_eleve', columns: ['eleve_id'])]
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà utilisé.')]
#[UniqueEntity(fields: ['enseignant'], message: 'Cet enseignant a déjà un compte.')]
#[UniqueEntity(fields: ['tuteur'], message: 'Ce tuteur a déjà un compte.')]
#[UniqueEntity(fields: ['eleve'], message: 'Cet élève a déjà un compte.')]
#[UnDirecteurActifAuMoins]
#[ApiResource(
    shortName: 'Utilisateur',
    operations: [
        new GetCollection(uriTemplate: '/utilisateurs'),
        new Get(uriTemplate: '/utilisateurs/{id}'),
        new Post(uriTemplate: '/utilisateurs', processor: UtilisateurProcessor::class),
        new Patch(uriTemplate: '/utilisateurs/{id}', processor: UtilisateurProcessor::class),
    ],
    security: Acces::ADMINISTRATION,
    normalizationContext: ['groups' => ['utilisateur:read']],
    denormalizationContext: ['groups' => ['utilisateur:write']],
    order: ['email' => 'ASC'],
    exceptionToStatus: DbExceptionMap::TO_STATUS,
)]
#[ApiFilter(SearchFilter::class, properties: ['email' => 'ipartial', 'role' => 'exact', 'actif' => 'exact'])]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['utilisateur:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    /** Empreinte du mot de passe : jamais exposée par l'API. */
    #[ORM\Column]
    private string $password = '';

    /** Mot de passe en clair reçu par l'API : non enregistré, haché par UtilisateurProcessor puis effacé. */
    #[Groups(['utilisateur:write'])]
    #[Assert\Length(min: 10, max: 4096, minMessage: 'Le mot de passe doit contenir au moins 10 caractères.')]
    private ?string $plainPassword = null;

    #[ORM\Column(length: 20, enumType: RoleUtilisateur::class)]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    private RoleUtilisateur $role;

    #[ORM\Column]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    private bool $actif = true;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    private ?string $nom = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    private ?string $prenoms = null;

    #[ORM\OneToOne(targetEntity: Enseignant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    private ?Enseignant $enseignant = null;

    #[ORM\OneToOne(targetEntity: Tuteur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    private ?Tuteur $tuteur = null;

    #[ORM\OneToOne(targetEntity: Eleve::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    private ?Eleve $eleve = null;

    #[ORM\Column]
    #[Groups(['utilisateur:read'])]
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

    public function setEmail(string $email): void
    {
        $this->email = mb_strtolower($email);
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRole(): RoleUtilisateur
    {
        return $this->role;
    }

    public function setRole(RoleUtilisateur $role): void
    {
        $this->role = $role;
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

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): void
    {
        $this->plainPassword = $plainPassword;
    }

    public function clearPlainPassword(): void
    {
        $this->plainPassword = null;
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

    public function setNom(?string $nom): void
    {
        $this->nom = $nom;
    }

    public function setPrenoms(?string $prenoms): void
    {
        $this->prenoms = $prenoms;
    }

    public function setIdentite(?string $nom, ?string $prenoms): void
    {
        $this->nom = $nom;
        $this->prenoms = $prenoms;
    }

    public function getEnseignant(): ?Enseignant
    {
        return $this->enseignant;
    }

    public function setEnseignant(?Enseignant $enseignant): void
    {
        $this->enseignant = $enseignant;
    }

    public function getTuteur(): ?Tuteur
    {
        return $this->tuteur;
    }

    public function setTuteur(?Tuteur $tuteur): void
    {
        $this->tuteur = $tuteur;
    }

    public function getEleve(): ?Eleve
    {
        return $this->eleve;
    }

    public function setEleve(?Eleve $eleve): void
    {
        $this->eleve = $eleve;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function eraseCredentials(): void
    {
    }

    /** Mot de passe obligatoire à la création ; lien avec la personne cohérent avec le rôle. */
    #[Assert\Callback]
    public function validerCoherence(ExecutionContextInterface $context): void
    {
        if (null === $this->id && (null === $this->plainPassword || '' === $this->plainPassword)) {
            $context->buildViolation('Un mot de passe est requis.')->atPath('plainPassword')->addViolation();
        }

        $interdit = static function (string $champ, string $message) use ($context): void {
            $context->buildViolation($message)->atPath($champ)->addViolation();
        };

        switch ($this->role) {
            case RoleUtilisateur::Enseignant:
                null === $this->enseignant && $interdit('enseignant', 'Un compte enseignant doit être lié à un enseignant.');
                null !== $this->tuteur && $interdit('tuteur', 'Un compte enseignant ne peut pas être lié à un tuteur.');
                null !== $this->eleve && $interdit('eleve', 'Un compte enseignant ne peut pas être lié à un élève.');
                break;
            case RoleUtilisateur::Tuteur:
                null === $this->tuteur && $interdit('tuteur', 'Un compte tuteur doit être lié à un tuteur.');
                null !== $this->enseignant && $interdit('enseignant', 'Un compte tuteur ne peut pas être lié à un enseignant.');
                null !== $this->eleve && $interdit('eleve', 'Un compte tuteur ne peut pas être lié à un élève.');
                break;
            case RoleUtilisateur::Eleve:
                null === $this->eleve && $interdit('eleve', 'Un compte élève doit être lié à un élève.');
                null !== $this->enseignant && $interdit('enseignant', 'Un compte élève ne peut pas être lié à un enseignant.');
                null !== $this->tuteur && $interdit('tuteur', 'Un compte élève ne peut pas être lié à un tuteur.');
                break;
            default: // directeur, secrétariat, comptable : un lien enseignant reste possible (directeur qui enseigne)
                null !== $this->tuteur && $interdit('tuteur', 'Ce rôle ne peut pas être lié à un tuteur.');
                null !== $this->eleve && $interdit('eleve', 'Ce rôle ne peut pas être lié à un élève.');
        }
    }
}
