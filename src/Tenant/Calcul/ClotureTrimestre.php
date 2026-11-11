<?php

declare(strict_types=1);

namespace App\Tenant\Calcul;

use App\Tenant\Entity\Classe;
use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\Matiere;
use App\Tenant\Entity\Periode;
use App\Tenant\Entity\ResultatMatiere;
use App\Tenant\Entity\ResultatPeriode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fermeture d'un trimestre : calcule et FIGE les résultats de toutes les classes de l'année, puis verrouille la saisie.
 * Réouverture : supprime les résultats figés (ils seront recalculés à la prochaine fermeture).
 */
final class ClotureTrimestre
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
        private readonly CalculMoyennes $calcul,
    ) {
    }

    /** @return int nombre de résultats figés (un par élève) */
    public function fermer(Periode $periode): int
    {
        if (!$periode->estOuverte()) {
            throw new \DomainException('Ce trimestre est déjà fermé.');
        }
        if (!$periode->getAnnee()->estModifiable()) {
            throw new \DomainException('Cette année scolaire est clôturée.');
        }

        return $this->tenantEntityManager->wrapInTransaction(function () use ($periode): int {
            $this->supprimerResultats($periode);

            $total = 0;
            foreach ($this->tenantEntityManager->getRepository(Classe::class)->findBy(['annee' => $periode->getAnnee()]) as $classe) {
                foreach ($this->calcul->calculerClasse($periode, $classe) as $ligne) {
                    $resultat = new ResultatPeriode(
                        $this->tenantEntityManager->getReference(Inscription::class, $ligne['inscription']),
                        $periode,
                        $ligne['moyenne'],
                        $ligne['rang'],
                        $ligne['effectif'],
                    );
                    $this->tenantEntityManager->persist($resultat);

                    foreach ($ligne['matieres'] as $matiere) {
                        $this->tenantEntityManager->persist(new ResultatMatiere(
                            $resultat,
                            $this->tenantEntityManager->getReference(Matiere::class, $matiere['matiere']),
                            $matiere['libelle'],
                            $matiere['coefficient'],
                            $matiere['moyenne'],
                            $matiere['rang'],
                        ));
                    }
                    ++$total;
                }
            }

            $periode->fermer();
            $this->tenantEntityManager->flush();

            return $total;
        });
    }

    public function rouvrir(Periode $periode): void
    {
        if ($periode->estOuverte()) {
            throw new \DomainException('Ce trimestre est déjà ouvert.');
        }
        if (!$periode->getAnnee()->estModifiable()) {
            throw new \DomainException('Cette année scolaire est clôturée.');
        }

        $this->tenantEntityManager->wrapInTransaction(function () use ($periode): void {
            $this->supprimerResultats($periode);
            $periode->rouvrir();
            $this->tenantEntityManager->flush();
        });
    }

    private function supprimerResultats(Periode $periode): void
    {
        // Les lignes de resultats_matiere partent avec leur résultat (ON DELETE CASCADE en base).
        $this->tenantEntityManager->createQuery('DELETE '.ResultatPeriode::class.' rp WHERE rp.periode = :periode')
            ->setParameter('periode', $periode)
            ->execute();
    }
}
