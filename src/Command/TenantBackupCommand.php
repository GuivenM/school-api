<?php

declare(strict_types=1);

namespace App\Command;

use App\Backup\SauvegardeService;
use App\Entity\Tenant;
use App\Entity\TenantStatus;
use App\Entity\TypeSauvegarde;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:backup', description: 'Sauvegarde la base d\'une école (ou de toutes) sur les destinations configurées')]
final class TenantBackupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SauvegardeService $sauvegardes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'École à sauvegarder')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Sauvegarder toutes les écoles actives')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'manuelle (défaut) ou quotidienne (tâche planifiée)', 'manuelle')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Commentaire libre');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = TypeSauvegarde::tryFrom((string) $input->getOption('type'));
        if (!\in_array($type, [TypeSauvegarde::Manuelle, TypeSauvegarde::Quotidienne], true)) {
            $io->error('--type doit valoir « manuelle » ou « quotidienne ».');

            return Command::INVALID;
        }

        $repository = $this->entityManager->getRepository(Tenant::class);
        if ($input->getOption('all')) {
            $tenants = $repository->findBy(['status' => TenantStatus::Active], ['slug' => 'ASC']);
        } elseif (null !== $slug = $input->getArgument('slug')) {
            $tenant = $repository->findOneBy(['slug' => $slug]);
            if (null === $tenant) {
                $io->error(sprintf('École « %s » introuvable.', $slug));

                return Command::FAILURE;
            }
            $tenants = [$tenant];
        } else {
            $io->error('Indique un slug ou utilise --all.');

            return Command::INVALID;
        }

        $echecs = 0;
        foreach ($tenants as $tenant) {
            try {
                $s = $this->sauvegardes->sauvegarder($tenant, $type, $input->getOption('note'));
                $partiel = [] !== $s->getDestinationsEchouees();
                $echecs += $partiel ? 1 : 0;
                $io->writeln(sprintf(
                    '%s  %s : #%d, %s Ko, copiée sur %s%s',
                    $partiel ? '<comment>PARTIEL</comment>' : '<info>OK</info>',
                    $tenant->getSlug(), $s->getId(), number_format($s->getTaille() / 1024, 0, ',', ' '),
                    implode(' + ', $s->getDestinations()),
                    $partiel ? ' (ÉCHEC sur '.implode(', ', $s->getDestinationsEchouees()).')' : '',
                ));
            } catch (\Throwable $e) {
                ++$echecs;
                $io->writeln(sprintf('<error>ECHEC</error> %s : %s', $tenant->getSlug(), $e->getMessage()));
            }
        }

        return 0 === $echecs ? Command::SUCCESS : Command::FAILURE;
    }
}
