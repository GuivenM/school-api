<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenancy\TenantProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:delete', description: 'SUPPRIME une école : sa base, son utilisateur MySQL et son enregistrement (irréversible)')]
final class TenantDeleteCommand extends Command
{
    public function __construct(private readonly TenantProvisioner $provisioner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'École à supprimer')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirme la suppression définitive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = (string) $input->getArgument('slug');

        if (!$input->getOption('force')) {
            $io->error('Suppression irréversible : ajoute --force pour confirmer.');

            return Command::FAILURE;
        }

        try {
            $this->provisioner->delete($slug);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('École « %s » supprimée.', $slug));

        return Command::SUCCESS;
    }
}
