<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenancy\TenantProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:create', description: 'Crée une école : base dédiée, utilisateur MySQL, migrations et données de départ')]
final class TenantCreateCommand extends Command
{
    public function __construct(private readonly TenantProvisioner $provisioner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Identifiant court (a-z, 0-9, _), ex. : ecole_demo')
            ->addArgument('name', InputArgument::REQUIRED, 'Nom complet de l\'école');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $tenant = $this->provisioner->create((string) $input->getArgument('slug'), (string) $input->getArgument('name'));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('École « %s » créée (base %s).', $tenant->getSlug(), $tenant->getDbName()));

        return Command::SUCCESS;
    }
}
