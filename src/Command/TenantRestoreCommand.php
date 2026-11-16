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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:restore', description: 'REMPLACE la base d\'une école par une sauvegarde (une sauvegarde de l\'état actuel est prise avant)')]
final class TenantRestoreCommand extends Command
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
            ->addArgument('slug', InputArgument::REQUIRED, 'École à restaurer')
            ->addArgument('id', InputArgument::REQUIRED, 'Numéro de la sauvegarde (voir tenant:backup:list)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirme le remplacement des données actuelles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sauvegarde = $this->entityManager->getRepository(Sauvegarde::class)->find((int) $input->getArgument('id'));

        if (null === $sauvegarde || $sauvegarde->getTenantSlug() !== $input->getArgument('slug')) {
            $io->error('Cette sauvegarde n\'existe pas pour cette école.');

            return Command::FAILURE;
        }
        if (!$input->getOption('force')) {
            $io->error(sprintf(
                'Les données actuelles de « %s » seront remplacées par la sauvegarde du %s. Ajoute --force pour confirmer. Mettre l\'application en maintenance pendant l\'opération.',
                $sauvegarde->getTenantSlug(), $sauvegarde->getCreatedAt()->format('d/m/Y H:i'),
            ));

            return Command::FAILURE;
        }

        try {
            $filet = $this->sauvegardes->restaurer($sauvegarde);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('École « %s » restaurée. État précédent conservé dans la sauvegarde n°%d.', $sauvegarde->getTenantSlug(), $filet->getId()));

        return Command::SUCCESS;
    }
}
