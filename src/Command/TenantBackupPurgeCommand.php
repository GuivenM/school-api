<?php

declare(strict_types=1);

namespace App\Command;

use App\Backup\SauvegardeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:backup:purge', description: 'Supprime les sauvegardes arrivées au terme de leur durée de conservation')]
final class TenantBackupPurgeCommand extends Command
{
    public function __construct(private readonly SauvegardeService $sauvegardes)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SymfonyStyle($input, $output))->success(sprintf('%d sauvegarde(s) supprimée(s).', $this->sauvegardes->purger()));

        return Command::SUCCESS;
    }
}
