<?php

declare(strict_types=1);

namespace App\Tenant\Calcul;

use App\Tenant\Entity\Classe;
use App\Tenant\Entity\ClasseMatiere;
use App\Tenant\Entity\DecisionFinAnnee;
use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\Note;
use App\Tenant\Entity\Parametre;
use App\Tenant\Entity\Periode;
use App\Tenant\Entity\ResultatMatiere;
use App\Tenant\Entity\ResultatPeriode;
use App\Tenant\Entity\StatutInscription;
use App\Tenant\Entity\StatutNote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Règles de calcul (notes sur 20, validées) :
 *  - moyenne d'une matière sur un trimestre = moyenne simple de toutes les notes « saisie » (absent, dispense, non_evalue ignorés) ;
 *  - moyenne du trimestre = Σ(moyenne matière × coefficient) / Σ coefficients des matières ayant au moins une note ;
 *  - moyenne annuelle = moyenne des moyennes de trimestre existantes (un élève arrivé au 2e trimestre est calculé sur 2 trimestres) ;
 *  - les moyennes sont arrondies à 2 décimales À CHAQUE ÉTAPE (matière, puis trimestre, puis année) : ce qui est imprimé sur le
 *    bulletin se recalcule à la main, et les rangs se fondent sur ces valeurs arrondies ;
 *  - rang : 1 + nombre d'élèves strictement meilleurs (les ex æquo partagent le même rang, le suivant saute).
 *
 * Trimestre ouvert : calcul en direct. Trimestre fermé : lecture des résultats figés.
 *
 * Forme d'une ligne : [inscription, eleve{id,nom,prenoms,matricule}, matieres[{matiere,libelle,coefficient,moyenne,rang}],
 *                      moyenne, rang, effectif].
 */
final class CalculMoyennes
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    /** Seuil d'admission de l'école (paramètre « seuil_admission », 10 par défaut), identique pour tous les niveaux. */
    public function seuilAdmission(): float
    {
        $valeur = $this->tenantEntityManager->find(Parametre::class, 'seuil_admission')?->getValeur();

        return null !== $valeur && is_numeric(str_replace(',', '.', $valeur)) ? (float) str_replace(',', '.', $valeur) : 10.0;
    }

    /** Décision proposée d'après la moyenne annuelle ; null = pas de moyenne, décision humaine nécessaire. */
    public function decisionCalculee(?float $moyenneAnnuelle): ?DecisionFinAnnee
    {
        if (null === $moyenneAnnuelle) {
            return null;
        }

        return $moyenneAnnuelle >= $this->seuilAdmission() ? DecisionFinAnnee::Admis : DecisionFinAnnee::Redouble;
    }

    /**
     * Moyenne et rang annuels de chaque élève d'une classe (moyenne des moyennes de trimestre existantes).
     *
     * @return array<int, array{moyenne: float, rang: int, effectif: int}> indexé par id d'inscription ; absent = aucune moyenne
     */
    public function moyennesAnnuelles(Classe $classe): array
    {
        $parInscription = [];
        foreach ($this->tenantEntityManager->getRepository(Periode::class)->findBy(['annee' => $classe->getAnnee()]) as $periode) {
            foreach ($this->resultatsClasse($periode, $classe) as $ligne) {
                if (null !== $ligne['moyenne']) {
                    $parInscription[$ligne['inscription']][] = $ligne['moyenne'];
                }
            }
        }

        $annuelles = array_map(static fn (array $m): float => round(array_sum($m) / \count($m), 2), $parInscription);
        $resultat = [];
        foreach ($annuelles as $id => $moyenne) {
            $resultat[$id] = [
                'moyenne' => $moyenne,
                'rang' => 1 + \count(array_filter($annuelles, static fn (float $a): bool => $a > $moyenne)),
                'effectif' => \count($annuelles),
            ];
        }

        return $resultat;
    }

    public function source(Periode $periode): string
    {
        return $periode->estOuverte() ? 'calcul' : 'snapshot';
    }

    /** @return list<array<string, mixed>> */
    public function resultatsClasse(Periode $periode, Classe $classe): array
    {
        return $periode->estOuverte() ? $this->calculerClasse($periode, $classe) : $this->lireResultatsFiges($periode, $classe);
    }

    /**
     * Calcul en direct à partir des notes, quel que soit l'état du trimestre (sert aussi à la fermeture).
     *
     * @return list<array<string, mixed>>
     */
    public function calculerClasse(Periode $periode, Classe $classe): array
    {
        $classeMatieres = $this->tenantEntityManager->getRepository(ClasseMatiere::class)->findBy(['classe' => $classe], ['id' => 'ASC']);
        $moyennes = $this->moyennesParMatiere($periode, $classe);

        $lignes = [];
        foreach ($this->inscriptionsConcernees($periode, $classe) as $inscription) {
            $matieres = [];
            $pondere = 0.0;
            $coefficients = 0.0;
            foreach ($classeMatieres as $classeMatiere) {
                $matiere = $classeMatiere->getMatiere();
                $brute = $moyennes[$inscription->getId()][$matiere->getId()] ?? null;
                $moyenne = null === $brute ? null : round($brute, 2);
                if (null !== $moyenne) {
                    $pondere += $moyenne * $classeMatiere->getCoefficient();
                    $coefficients += $classeMatiere->getCoefficient();
                }
                $matieres[] = [
                    'matiere' => $matiere->getId(),
                    'libelle' => $matiere->getLibelle(),
                    'coefficient' => $classeMatiere->getCoefficient(),
                    'moyenne' => $moyenne,
                    'rang' => null,
                ];
            }

            $eleve = $inscription->getEleve();
            $lignes[] = [
                'inscription' => $inscription->getId(),
                'eleve' => ['id' => $eleve->getId(), 'nom' => $eleve->getNom(), 'prenoms' => $eleve->getPrenoms(), 'matricule' => $eleve->getMatricule()],
                'matieres' => $matieres,
                'moyenne' => $coefficients > 0 ? round($pondere / $coefficients, 2) : null,
                'rang' => null,
            ];
        }

        return $this->attribuerRangs($lignes);
    }

    /**
     * Bulletin d'une inscription : un bloc par trimestre de l'année + moyenne et rang annuels.
     *
     * @return array<string, mixed>
     */
    public function bulletin(Inscription $inscription): array
    {
        $classe = $inscription->getClasse();
        $periodes = $this->tenantEntityManager->getRepository(Periode::class)->findBy(['annee' => $inscription->getAnnee()], ['dateDebut' => 'ASC', 'numero' => 'ASC']);

        $blocs = [];
        $moyennesParInscription = [];
        foreach ($periodes as $periode) {
            $lignes = $this->resultatsClasse($periode, $classe);
            $mienne = null;
            foreach ($lignes as $ligne) {
                if (null !== $ligne['moyenne']) {
                    $moyennesParInscription[$ligne['inscription']][] = $ligne['moyenne'];
                }
                if ($ligne['inscription'] === $inscription->getId()) {
                    $mienne = $ligne;
                }
            }

            $blocs[] = [
                'periode' => $periode->getId(),
                'numero' => $periode->getNumero(),
                'libelle' => $periode->getLibelle(),
                'statut' => $periode->getStatut()->value,
                'source' => $this->source($periode),
                'moyenne' => $mienne['moyenne'] ?? null,
                'rang' => $mienne['rang'] ?? null,
                'effectif' => \count(array_filter($lignes, static fn (array $l): bool => null !== $l['moyenne'])),
                'matieres' => $mienne['matieres'] ?? [],
            ];
        }

        $annuelles = array_map(static fn (array $m): float => round(array_sum($m) / \count($m), 2), $moyennesParInscription);
        $mienneAnnuelle = $annuelles[$inscription->getId()] ?? null;
        $rangAnnuel = null === $mienneAnnuelle ? null : 1 + \count(array_filter($annuelles, static fn (float $a): bool => $a > $mienneAnnuelle));

        $eleve = $inscription->getEleve();

        return [
            'inscription' => $inscription->getId(),
            'eleve' => ['id' => $eleve->getId(), 'nom' => $eleve->getNom(), 'prenoms' => $eleve->getPrenoms(), 'matricule' => $eleve->getMatricule()],
            'classe' => $classe->getId(),
            'annee' => $inscription->getAnnee()->getId(),
            'periodes' => $blocs,
            'moyenneAnnuelle' => $mienneAnnuelle,
            'rangAnnuel' => $rangAnnuel,
            'effectifAnnuel' => \count($annuelles),
        ];
    }

    /** @return list<Inscription> inscriptions en cours de la classe + celles qui ont des notes dans ce trimestre (élève parti en cours d'année) */
    private function inscriptionsConcernees(Periode $periode, Classe $classe): array
    {
        return $this->tenantEntityManager->createQuery(
            'SELECT i, e FROM '.Inscription::class.' i JOIN i.eleve e
             WHERE i.classe = :classe
               AND (i.statut = :enCours OR EXISTS (
                    SELECT n.id FROM '.Note::class.' n JOIN n.evaluation ev WHERE n.inscription = i AND ev.periode = :periode))
             ORDER BY e.nom ASC, e.prenoms ASC, i.id ASC'
        )
            ->setParameter('classe', $classe)
            ->setParameter('periode', $periode)
            ->setParameter('enCours', StatutInscription::EnCours)
            ->getResult();
    }

    /** @return array<int, array<int, float>> [inscription][matière] => moyenne brute des notes « saisie » */
    private function moyennesParMatiere(Periode $periode, Classe $classe): array
    {
        $lignes = $this->tenantEntityManager->createQueryBuilder()
            ->select('IDENTITY(n.inscription) AS ins', 'IDENTITY(cm.matiere) AS mat', 'AVG(n.valeur) AS moy')
            ->from(Note::class, 'n')
            ->join('n.evaluation', 'e')
            ->join('e.classeMatiere', 'cm')
            ->where('e.periode = :periode')
            ->andWhere('cm.classe = :classe')
            ->andWhere('n.statut = :statut')
            ->groupBy('n.inscription', 'cm.matiere')
            ->setParameter('periode', $periode)
            ->setParameter('classe', $classe)
            ->setParameter('statut', StatutNote::Saisie)
            ->getQuery()->getArrayResult();

        $moyennes = [];
        foreach ($lignes as $ligne) {
            $moyennes[(int) $ligne['ins']][(int) $ligne['mat']] = (float) $ligne['moy'];
        }

        return $moyennes;
    }

    /** @return list<array<string, mixed>> */
    private function lireResultatsFiges(Periode $periode, Classe $classe): array
    {
        /** @var list<ResultatPeriode> $resultats */
        $resultats = $this->tenantEntityManager->createQueryBuilder()
            ->select('rp', 'i', 'e')
            ->from(ResultatPeriode::class, 'rp')
            ->join('rp.inscription', 'i')
            ->join('i.eleve', 'e')
            ->where('rp.periode = :periode')
            ->andWhere('i.classe = :classe')
            ->orderBy('e.nom', 'ASC')->addOrderBy('e.prenoms', 'ASC')->addOrderBy('i.id', 'ASC')
            ->setParameter('periode', $periode)
            ->setParameter('classe', $classe)
            ->getQuery()->getResult();

        /** @var list<ResultatMatiere> $matieres */
        $matieres = $this->tenantEntityManager->createQueryBuilder()
            ->select('rm', 'rp')
            ->from(ResultatMatiere::class, 'rm')
            ->join('rm.resultatPeriode', 'rp')
            ->join('rp.inscription', 'i')
            ->where('rp.periode = :periode')
            ->andWhere('i.classe = :classe')
            ->orderBy('rm.id', 'ASC')
            ->setParameter('periode', $periode)
            ->setParameter('classe', $classe)
            ->getQuery()->getResult();

        $parResultat = [];
        foreach ($matieres as $matiere) {
            $parResultat[$matiere->getResultatPeriode()->getId()][] = [
                'matiere' => $matiere->getMatiere()->getId(),
                'libelle' => $matiere->getMatiereLibelle(),
                'coefficient' => $matiere->getCoefficient(),
                'moyenne' => $matiere->getMoyenne(),
                'rang' => $matiere->getRang(),
            ];
        }

        $lignes = [];
        foreach ($resultats as $resultat) {
            $eleve = $resultat->getInscription()->getEleve();
            $lignes[] = [
                'inscription' => $resultat->getInscription()->getId(),
                'eleve' => ['id' => $eleve->getId(), 'nom' => $eleve->getNom(), 'prenoms' => $eleve->getPrenoms(), 'matricule' => $eleve->getMatricule()],
                'matieres' => $parResultat[$resultat->getId()] ?? [],
                'moyenne' => $resultat->getMoyenne(),
                'rang' => $resultat->getRang(),
                'effectif' => $resultat->getEffectif(),
            ];
        }

        return $lignes;
    }

    /**
     * @param list<array<string, mixed>> $lignes
     *
     * @return list<array<string, mixed>>
     */
    private function attribuerRangs(array $lignes): array
    {
        $rangs = $this->rangs(array_map(static fn (array $l): ?float => $l['moyenne'], $lignes));
        $effectif = \count(array_filter($lignes, static fn (array $l): bool => null !== $l['moyenne']));

        foreach ($lignes as $i => $ligne) {
            $lignes[$i]['rang'] = $rangs[$i];
            $lignes[$i]['effectif'] = $effectif;
        }

        if ([] !== $lignes) {
            foreach (array_keys($lignes[0]['matieres']) as $m) {
                $rangsMatiere = $this->rangs(array_map(static fn (array $l): ?float => $l['matieres'][$m]['moyenne'], $lignes));
                foreach ($lignes as $i => $ligne) {
                    $lignes[$i]['matieres'][$m]['rang'] = $rangsMatiere[$i];
                }
            }
        }

        return $lignes;
    }

    /**
     * @param array<int, ?float> $valeurs
     *
     * @return array<int, ?int>
     */
    private function rangs(array $valeurs): array
    {
        $rangs = [];
        foreach ($valeurs as $i => $valeur) {
            if (null === $valeur) {
                $rangs[$i] = null;
                continue;
            }
            $meilleurs = 0;
            foreach ($valeurs as $autre) {
                if (null !== $autre && $autre > $valeur) {
                    ++$meilleurs;
                }
            }
            $rangs[$i] = $meilleurs + 1;
        }

        return $rangs;
    }
}
