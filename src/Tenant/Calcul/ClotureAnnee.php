<?php

declare(strict_types=1);

namespace App\Tenant\Calcul;

use App\Tenant\Entity\AnneeScolaire;
use App\Tenant\Entity\Classe;
use App\Tenant\Entity\DecisionFinAnnee;
use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\Niveau;
use App\Tenant\Entity\Periode;
use App\Tenant\Entity\StatutAnnee;
use App\Tenant\Entity\StatutEleve;
use App\Tenant\Entity\StatutInscription;
use App\Tenant\Entity\TypeInscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clôture d'une année scolaire et passage à la suivante, en deux temps :
 *  - planifier() : simulation, ne modifie rien. Liste, pour chaque élève, la moyenne annuelle, la décision (calculée ou prise par le
 *    conseil de classe) et son devenir, ainsi que les « blocages » à lever avant de pouvoir clôturer ;
 *  - cloturer() : exécute le plan en une seule transaction (tout ou rien).
 *
 * Règles : décision calculée = moyenne annuelle ≥ seuil d'admission (même seuil pour tous les niveaux). Sans moyenne, une décision
 * humaine est obligatoire. « admis » → réinscription dans le niveau suivant ; « redouble » → redoublement dans le même niveau ;
 * « exclu » → sortie. Un élève admis dans le dernier niveau de l'école (aucun niveau suivant) termine son cycle : il sort de l'école
 * (les examens d'État BEPC/BAC ne sont pas gérés ici).
 */
final class ClotureAnnee
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
        private readonly CalculMoyennes $calcul,
    ) {
    }

    /**
     * @param array<int, array{admis?: ?int, redoublants?: ?int}> $affectations classe source (id) => classes de destination (id)
     *
     * @return array<string, mixed>
     */
    public function planifier(AnneeScolaire $annee, ?AnneeScolaire $suivante, array $affectations = []): array
    {
        $blocages = [];
        $resume = ['passages' => 0, 'redoublements' => 0, 'fins_de_cycle' => 0, 'exclusions' => 0, 'sans_decision' => 0];

        if (StatutAnnee::Active !== $annee->getStatut()) {
            $blocages['annee'] = ['code' => 'annee_non_active', 'message' => 'Seule l\'année « active » peut être clôturée.'];
        }

        $periodes = $this->tenantEntityManager->getRepository(Periode::class)->findBy(['annee' => $annee], ['numero' => 'ASC']);
        if ([] === $periodes) {
            $blocages['periodes'] = ['code' => 'aucun_trimestre', 'message' => 'Cette année n\'a aucun trimestre.'];
        }
        foreach ($periodes as $periode) {
            if ($periode->estOuverte()) {
                $blocages['periode|'.$periode->getId()] = ['code' => 'trimestre_ouvert', 'periode' => $periode->getId(), 'message' => sprintf('Le « %s » n\'est pas fermé.', $periode->getLibelle())];
            }
        }

        $classesSuivante = [];
        $suivanteValide = false;
        if (null === $suivante) {
            $blocages['suivante'] = ['code' => 'annee_suivante_requise', 'message' => 'Indiquer l\'année scolaire suivante (statut « brouillon »).'];
        } elseif ($suivante === $annee || StatutAnnee::Brouillon !== $suivante->getStatut() || $suivante->getDateDebut() <= $annee->getDateFin()) {
            $blocages['suivante'] = ['code' => 'annee_suivante_invalide', 'message' => 'L\'année suivante doit être différente, au statut « brouillon » et commencer après la fin de l\'année clôturée.'];
        } else {
            $suivanteValide = true;
            foreach ($this->tenantEntityManager->getRepository(Classe::class)->findBy(['annee' => $suivante]) as $classe) {
                $classesSuivante[$classe->getId()] = $classe;
            }
        }

        $seuil = $this->calcul->seuilAdmission();
        $classes = [];
        $actions = [];

        foreach ($this->tenantEntityManager->getRepository(Classe::class)->findBy(['annee' => $annee], ['libelle' => 'ASC']) as $classe) {
            $annuelles = $this->calcul->moyennesAnnuelles($classe);
            $eleves = [];

            foreach ($this->inscriptionsEnCours($classe) as $inscription) {
                $bilan = $annuelles[$inscription->getId()] ?? null;
                $moyenne = $bilan['moyenne'] ?? null;
                $auto = $this->calcul->decisionCalculee($moyenne);
                $finale = $inscription->getDecision() ?? $auto;

                $devenir = 'decision_requise';
                $destination = null;
                $type = null;

                if (null === $finale) {
                    $blocages['decision|'.$inscription->getId()] = [
                        'code' => 'decision_requise',
                        'inscription' => $inscription->getId(),
                        'message' => sprintf('%s %s n\'a aucune moyenne : une décision du conseil de classe est requise.', $inscription->getEleve()->getPrenoms(), $inscription->getEleve()->getNom()),
                    ];
                    ++$resume['sans_decision'];
                } elseif (DecisionFinAnnee::Exclu === $finale) {
                    $devenir = 'exclusion';
                    ++$resume['exclusions'];
                } elseif (DecisionFinAnnee::Admis === $finale) {
                    $niveauSuivant = $classe->getNiveau()->getNiveauSuivant();
                    if (null === $niveauSuivant) {
                        $devenir = 'fin_de_cycle';
                        ++$resume['fins_de_cycle'];
                    } else {
                        $devenir = 'passage';
                        $type = TypeInscription::Reinscription;
                        $destination = $suivanteValide ? $this->destination($affectations, 'admis', $classe, $niveauSuivant, $classesSuivante, $blocages) : null;
                        ++$resume['passages'];
                    }
                } else {
                    $devenir = 'redoublement';
                    $type = TypeInscription::Redoublement;
                    $destination = $suivanteValide ? $this->destination($affectations, 'redoublants', $classe, $classe->getNiveau(), $classesSuivante, $blocages) : null;
                    ++$resume['redoublements'];
                }

                if (null !== $destination && null !== $this->tenantEntityManager->getRepository(Inscription::class)->findOneBy(['eleve' => $inscription->getEleve(), 'annee' => $suivante])) {
                    $blocages['deja|'.$inscription->getId()] = ['code' => 'deja_inscrit_annee_suivante', 'inscription' => $inscription->getId(), 'message' => sprintf('%s %s est déjà inscrit dans l\'année suivante.', $inscription->getEleve()->getPrenoms(), $inscription->getEleve()->getNom())];
                }

                $eleve = $inscription->getEleve();
                $eleves[] = [
                    'inscription' => $inscription->getId(),
                    'eleve' => ['id' => $eleve->getId(), 'nom' => $eleve->getNom(), 'prenoms' => $eleve->getPrenoms(), 'matricule' => $eleve->getMatricule()],
                    'moyenneAnnuelle' => $moyenne,
                    'rang' => $bilan['rang'] ?? null,
                    'effectif' => $bilan['effectif'] ?? null,
                    'decisionCalculee' => $auto?->value,
                    'decisionConseil' => $inscription->getDecision()?->value,
                    'motif' => $inscription->getDecisionMotif(),
                    'decisionFinale' => $finale?->value,
                    'devenir' => $devenir,
                    'classeDestination' => $destination?->getId(),
                ];

                if (null !== $finale) {
                    $actions[] = [
                        'inscription' => $inscription,
                        'moyenne' => $moyenne,
                        'rang' => $bilan['rang'] ?? null,
                        'effectif' => $bilan['effectif'] ?? null,
                        'auto' => $auto,
                        'finale' => $finale,
                        'destination' => $destination,
                        'type' => $type,
                    ];
                }
            }

            $classes[] = [
                'classe' => $classe->getId(),
                'libelle' => $classe->getLibelle(),
                'niveau' => ['id' => $classe->getNiveau()->getId(), 'code' => $classe->getNiveau()->getCode()],
                'eleves' => $eleves,
            ];
        }

        return ['seuil' => $seuil, 'blocages' => array_values($blocages), 'classes' => $classes, 'resume' => $resume, 'actions' => $actions];
    }

    /**
     * @param array<int, array{admis?: ?int, redoublants?: ?int}> $affectations
     *
     * @return array<string, int> le résumé (passages, redoublements...)
     */
    public function cloturer(AnneeScolaire $annee, ?AnneeScolaire $suivante, array $affectations = []): array
    {
        $plan = $this->planifier($annee, $suivante, $affectations);
        if ([] !== $plan['blocages'] || null === $suivante) {
            throw new ClotureImpossibleException($plan['blocages']);
        }

        $this->tenantEntityManager->wrapInTransaction(function () use ($annee, $suivante, $plan): void {
            foreach ($plan['actions'] as $action) {
                /** @var Inscription $inscription */
                $inscription = $action['inscription'];
                $inscription->enregistrerBilan($action['moyenne'], $action['rang'], $action['effectif'], $action['auto'], $action['finale']);

                if (null !== $action['destination']) {
                    $nouvelle = new Inscription();
                    $nouvelle->setEleve($inscription->getEleve());
                    $nouvelle->setAnnee($suivante);
                    $nouvelle->setClasse($action['destination']);
                    $nouvelle->setType($action['type']);
                    $nouvelle->setDateInscription($suivante->getDateDebut());
                    $this->tenantEntityManager->persist($nouvelle);
                } else {
                    // Exclusion ou fin de cycle : l'élève quitte l'école (il redevient « actif » s'il est réinscrit plus tard).
                    $inscription->getEleve()->setStatut(StatutEleve::Sorti);
                }
            }

            $annee->marquerCloturee();
            $this->tenantEntityManager->flush();
        });

        return $plan['resume'];
    }

    /** @return list<Inscription> */
    private function inscriptionsEnCours(Classe $classe): array
    {
        return $this->tenantEntityManager->createQuery(
            'SELECT i, e FROM '.Inscription::class.' i JOIN i.eleve e WHERE i.classe = :classe AND i.statut = :statut ORDER BY e.nom ASC, e.prenoms ASC, i.id ASC'
        )
            ->setParameter('classe', $classe)
            ->setParameter('statut', StatutInscription::EnCours)
            ->getResult();
    }

    /**
     * Classe de destination d'une classe source : celle demandée explicitement, sinon l'unique classe du niveau voulu.
     *
     * @param array<int, array{admis?: ?int, redoublants?: ?int}> $affectations
     * @param array<int, Classe>                                  $classesSuivante
     * @param array<string, array<string, mixed>>                 $blocages
     */
    private function destination(array $affectations, string $cle, Classe $source, Niveau $niveau, array $classesSuivante, array &$blocages): ?Classe
    {
        $cleBlocage = 'dest|'.$source->getId().'|'.$cle;
        $demandee = $affectations[$source->getId()][$cle] ?? null;

        if (null !== $demandee) {
            $classe = $classesSuivante[$demandee] ?? null;
            if (null === $classe || $classe->getNiveau() !== $niveau) {
                $blocages[$cleBlocage] = ['code' => 'affectation_invalide', 'classe' => $source->getId(), 'message' => sprintf('La classe choisie pour « %s » doit appartenir à l\'année suivante et au niveau « %s ».', $source->getLibelle(), $niveau->getCode())];

                return null;
            }

            return $classe;
        }

        $candidates = array_values(array_filter($classesSuivante, static fn (Classe $c): bool => $c->getNiveau() === $niveau));
        if (1 === \count($candidates)) {
            return $candidates[0];
        }

        $blocages[$cleBlocage] = [
            'code' => [] === $candidates ? 'classe_destination_manquante' : 'classe_destination_ambigue',
            'classe' => $source->getId(),
            'message' => [] === $candidates
                ? sprintf('Créer, dans l\'année suivante, une classe du niveau « %s » pour les élèves de « %s ».', $niveau->getCode(), $source->getLibelle())
                : sprintf('Plusieurs classes « %s » existent dans l\'année suivante : choisir celle des élèves de « %s » (champ « %s »).', $niveau->getCode(), $source->getLibelle(), $cle),
        ];

        return null;
    }
}
