<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SuperAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'admin:create', description: 'Crée un super-admin de la plateforme (base centrale)')]
final class AdminCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email de connexion')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe (à éviter : visible dans l\'historique du shell ; sinon il est demandé)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower((string) $input->getArgument('email'));

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Email invalide.');

            return Command::FAILURE;
        }
        if (null !== $this->entityManager->getRepository(SuperAdmin::class)->findOneBy(['email' => $email])) {
            $io->error('Ce super-admin existe déjà.');

            return Command::FAILURE;
        }

        $password = $input->getOption('password') ?? $io->askHidden('Mot de passe (10 caractères minimum)');
        if (!\is_string($password) || mb_strlen($password) < 10) {
            $io->error('Mot de passe trop court (10 caractères minimum).');

            return Command::FAILURE;
        }

        $admin = new SuperAdmin($email);
        $admin->setPassword($this->hasher->hashPassword($admin, $password));
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $io->success(sprintf('Super-admin %s créé.', $email));

        return Command::SUCCESS;
    }
}
