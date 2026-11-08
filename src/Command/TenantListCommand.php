<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:list', description: 'Liste les écoles')]
final class TenantListCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = [];
        foreach ($this->entityManager->getRepository(Tenant::class)->findBy([], ['slug' => 'ASC']) as $tenant) {
            $rows[] = [
                $tenant->getSlug(),
                $tenant->getName(),
                $tenant->getStatus()->value,
                $tenant->getDbName(),
                $tenant->getCreatedAt()->format('Y-m-d H:i'),
            ];
        }

        $io->table(['Slug', 'Nom', 'Statut', 'Base', 'Créée le'], $rows);

        return Command::SUCCESS;
    }
}
