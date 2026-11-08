<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Entity\TenantStatus;
use App\Tenancy\TenantMigrator;
use App\Tenancy\TenantSwitcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:migrate', description: 'Applique les migrations tenant sur une école ou sur toutes les écoles actives')]
final class TenantMigrateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantSwitcher $switcher,
        private readonly TenantMigrator $migrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'École à migrer')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Migrer toutes les écoles actives');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
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

        $failed = [];
        foreach ($tenants as $tenant) {
            try {
                $this->switcher->switchTo($tenant);
                $count = $this->migrator->migrate();
                $io->writeln(sprintf('<info>OK</info>   %s : %d migration(s) exécutée(s)', $tenant->getSlug(), $count));
            } catch (\Throwable $e) {
                $failed[] = $tenant->getSlug();
                $io->writeln(sprintf('<error>ECHEC</error> %s : %s', $tenant->getSlug(), $e->getMessage()));
            } finally {
                $this->switcher->clear();
            }
        }

        if ([] !== $failed) {
            $io->error(sprintf('Échec pour : %s. Relance avec « tenant:migrate <slug> » pour ces écoles.', implode(', ', $failed)));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d école(s) à jour.', \count($tenants)));

        return Command::SUCCESS;
    }
}
