<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Entity\TenantStatus;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\RoleUtilisateur;
use App\Tenant\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'tenant:user:create', description: 'Crée un utilisateur dans la base d\'une école')]
final class TenantUserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $landlordEntityManager,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
        private readonly TenantSwitcher $switcher,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $roles = implode(', ', array_map(static fn (RoleUtilisateur $r) => $r->value, RoleUtilisateur::cases()));

        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'École')
            ->addArgument('email', InputArgument::REQUIRED, 'Email de connexion')
            ->addArgument('role', InputArgument::REQUIRED, 'Rôle : '.$roles)
            ->addOption('nom', null, InputOption::VALUE_REQUIRED)
            ->addOption('prenoms', null, InputOption::VALUE_REQUIRED)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe (à éviter : visible dans l\'historique du shell ; sinon il est demandé)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower((string) $input->getArgument('email'));
        $role = RoleUtilisateur::tryFrom((string) $input->getArgument('role'));

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Email invalide.');

            return Command::FAILURE;
        }
        if (null === $role) {
            $io->error('Rôle inconnu.');

            return Command::FAILURE;
        }

        $tenant = $this->landlordEntityManager->getRepository(Tenant::class)
            ->findOneBy(['slug' => $input->getArgument('slug'), 'status' => TenantStatus::Active]);
        if (null === $tenant) {
            $io->error('École introuvable ou inactive.');

            return Command::FAILURE;
        }

        $password = $input->getOption('password') ?? $io->askHidden('Mot de passe (10 caractères minimum)');
        if (!\is_string($password) || mb_strlen($password) < 10) {
            $io->error('Mot de passe trop court (10 caractères minimum).');

            return Command::FAILURE;
        }

        try {
            $this->switcher->switchTo($tenant);

            if (null !== $this->tenantEntityManager->getRepository(Utilisateur::class)->findOneBy(['email' => $email])) {
                $io->error('Cet utilisateur existe déjà dans cette école.');

                return Command::FAILURE;
            }

            $user = new Utilisateur($email, $role);
            $user->setIdentite($input->getOption('nom'), $input->getOption('prenoms'));
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->tenantEntityManager->persist($user);
            $this->tenantEntityManager->flush();
        } finally {
            $this->switcher->clear();
        }

        $io->success(sprintf('Utilisateur %s (%s) créé dans « %s ».', $email, $role->value, $tenant->getSlug()));

        return Command::SUCCESS;
    }
}
