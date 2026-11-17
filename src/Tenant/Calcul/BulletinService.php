<?php

declare(strict_types=1);

namespace App\Tenant\Calcul;

use App\Tenant\Entity\Appreciation;
use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\Parametre;
use App\Tenant\Entity\StatutInscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Assemble tout ce qu'un bulletin affiche : moyennes et rangs (CalculMoyennes), appréciations, élève, classe, école, décision. */
final class BulletinService
{
    public function __construct(
        private readonly CalculMoyennes $calcul,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function construire(Inscription $inscription): array
    {
        $bulletin = $this->calcul->bulletin($inscription);

        $textes = [];
        foreach ($this->tenantEntityManager->getRepository(Appreciation::class)->findBy(['inscription' => $inscription]) as $appreciation) {
            $textes[$appreciation->getPeriode()->getId()][$appreciation->getMatiere()?->getId() ?? 0] = $appreciation->getTexte();
        }
        foreach ($bulletin['periodes'] as $i => $bloc) {
            $bulletin['periodes'][$i]['appreciation'] = $textes[$bloc['periode']][0] ?? null;
            foreach ($bloc['matieres'] as $j => $matiere) {
                $bulletin['periodes'][$i]['matieres'][$j]['appreciation'] = $textes[$bloc['periode']][$matiere['matiere']] ?? null;
            }
        }

        $eleve = $inscription->getEleve();
        $classe = $inscription->getClasse();
        $annee = $inscription->getAnnee();
        $termine = StatutInscription::Terminee === $inscription->getStatut();

        $bulletin['eleve'] += [
            'dateNaissance' => $eleve->getDateNaissance()?->format('Y-m-d'),
            'sexe' => $eleve->getSexe()?->value,
        ];

        return $bulletin + [
            'ecole' => $this->tenantEntityManager->find(Parametre::class, 'nom_ecole')?->getValeur() ?? '',
            'anneeLibelle' => $annee->getLibelle(),
            'anneeStatut' => $annee->getStatut()->value,
            'classeLibelle' => $classe->getLibelle(),
            'niveau' => $classe->getNiveau()->getCode(),
            'finDeCycle' => null === $classe->getNiveau()->getNiveauSuivant(),
            'decision' => $termine ? $inscription->getDecision()?->value : null,
        ];
    }
}
