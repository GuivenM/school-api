<?php

declare(strict_types=1);

namespace App\Command;

use App\Backup\SauvegardeService;
use App\Entity\Sauvegarde;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:backup:verify', description: 'Vérifie une sauvegarde sur chaque destination (empreinte + déchiffrement)')]
final class TenantBackupVerifyCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SauvegardeService $sauvegardes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Numéro de la sauvegarde (voir tenant:backup:list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sauvegarde = $this->entityManager->getRepository(Sauvegarde::class)->find((int) $input->getArgument('id'));
        if (null === $sauvegarde) {
            $io->error('Sauvegarde introuvable.');

            return Command::FAILURE;
        }

        $ok = true;
        foreach ($this->sauvegardes->verifier($sauvegarde) as $destination => $resultat) {
            $io->writeln(sprintf('%s  %s : %s', 'ok' === $resultat ? '<info>OK</info>' : '<error>KO</error>', $destination, $resultat));
            $ok = $ok && 'ok' === $resultat;
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
