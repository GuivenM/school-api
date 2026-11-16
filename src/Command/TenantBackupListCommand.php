<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sauvegarde;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:backup:list', description: 'Liste les sauvegardes (les plus récentes d\'abord)')]
final class TenantBackupListCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Filtrer sur une école')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de lignes', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $criteres = null === $input->getArgument('slug') ? [] : ['tenantSlug' => $input->getArgument('slug')];

        $lignes = [];
        foreach ($this->entityManager->getRepository(Sauvegarde::class)->findBy($criteres, ['id' => 'DESC'], max(1, (int) $input->getOption('limit'))) as $s) {
            $lignes[] = [
                $s->getId(), $s->getTenantSlug(), $s->getType()->value, $s->getCreatedAt()->format('Y-m-d H:i'),
                number_format($s->getTaille() / 1024, 0, ',', ' ').' Ko', implode(' + ', $s->getDestinations()), $s->getAnneeLibelle() ?? '', $s->getNote() ?? '',
            ];
        }
        $io->table(['N°', 'École', 'Type', 'Date', 'Taille', 'Copies', 'Année', 'Note'], $lignes);

        return Command::SUCCESS;
    }
}
