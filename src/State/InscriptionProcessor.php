<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\StatutEleve;
use App\Tenant\Entity\StatutInscription;
use App\Tenant\MatriculeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * À l'enregistrement d'une inscription :
 *  - attribue le matricule à l'élève s'il n'en a pas encore ;
 *  - répercute un transfert ou un abandon sur le statut de l'élève (et le rétablit si l'inscription redevient « en_cours ») ;
 *  - calcule les avertissements (effectif maximum dépassé : information, jamais un blocage).
 */
final class InscriptionProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly MatriculeGenerator $matricules,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Inscription) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $eleve = $data->getEleve();
        if (null !== $eleve) {
            if (null === $eleve->getMatricule() && null !== $data->getAnnee()) {
                $eleve->setMatricule($this->matricules->generer($data->getAnnee()));
            }

            match ($data->getStatut()) {
                StatutInscription::Transferee => $eleve->setStatut(StatutEleve::Transfere),
                StatutInscription::Abandonnee => $eleve->setStatut(StatutEleve::Sorti),
                StatutInscription::EnCours => \in_array($eleve->getStatut(), [StatutEleve::Transfere, StatutEleve::Sorti], true)
                    ? $eleve->setStatut(StatutEleve::Actif)
                    : null,
                default => null,
            };
        }

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        if ($result instanceof Inscription) {
            $result->setAvertissements($this->avertissements($result));
        }

        return $result;
    }

    /** @return list<string> */
    private function avertissements(Inscription $inscription): array
    {
        $classe = $inscription->getClasse();
        $max = $classe?->getEffectifMax();
        if (null === $classe || null === $max) {
            return [];
        }

        $effectif = (int) $this->tenantEntityManager->getRepository(Inscription::class)->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.classe = :classe')
            ->andWhere('i.statut = :statut')
            ->setParameter('classe', $classe)
            ->setParameter('statut', StatutInscription::EnCours)
            ->getQuery()->getSingleScalarResult();

        return $effectif > $max
            ? [sprintf('Effectif maximum dépassé : %d élèves inscrits pour %d places.', $effectif, $max)]
            : [];
    }
}
