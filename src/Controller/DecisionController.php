<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenant\Calcul\CalculMoyennes;
use App\Tenant\Entity\DecisionFinAnnee;
use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\StatutInscription;
use App\Tenant\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Décision du conseil de classe pour un élève, avant la clôture de l'année. */
final class DecisionController
{
    public function __construct(
        private readonly CalculMoyennes $calcul,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    /** POST {"decision": "admis|redouble|exclu", "motif": "..."} : le motif est obligatoire si la décision diffère du calcul (ou s'il n'y a pas de moyenne). */
    #[Route('/api/inscriptions/{id}/decision', name: 'api_inscription_decision', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SECRETARIAT')]
    public function definir(Inscription $inscription, Request $request, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        if (null !== ($refus = $this->verifier($inscription))) {
            return $refus;
        }

        $corps = json_decode($request->getContent(), true);
        $decision = \is_array($corps) ? DecisionFinAnnee::tryFrom((string) ($corps['decision'] ?? '')) : null;
        $motif = \is_array($corps) && \is_string($corps['motif'] ?? null) ? trim($corps['motif']) : '';

        if (null === $decision) {
            return new JsonResponse(['error' => 'Décision inconnue (admis, redouble, exclu).'], 422);
        }
        if (mb_strlen($motif) > 255) {
            return new JsonResponse(['error' => 'Le motif ne peut pas dépasser 255 caractères.'], 422);
        }

        $bilan = $this->calcul->moyennesAnnuelles($inscription->getClasse())[$inscription->getId()] ?? null;
        $calculee = $this->calcul->decisionCalculee($bilan['moyenne'] ?? null);
        if ($decision !== $calculee && mb_strlen($motif) < 3) {
            return new JsonResponse(['error' => 'Un motif est requis : cette décision diffère de la décision calculée ('.($calculee?->value ?? 'aucune moyenne').').'], 422);
        }

        $inscription->definirDecision($decision, '' === $motif ? null : $motif, $utilisateur->getUserIdentifier());
        $this->tenantEntityManager->flush();

        return new JsonResponse($this->presenter($inscription, $calculee, $bilan['moyenne'] ?? null));
    }

    /** Annule la décision du conseil : retour à la décision calculée. */
    #[Route('/api/inscriptions/{id}/decision', name: 'api_inscription_decision_annuler', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SECRETARIAT')]
    public function annuler(Inscription $inscription): JsonResponse
    {
        if (null !== ($refus = $this->verifier($inscription))) {
            return $refus;
        }

        $inscription->effacerDecision();
        $this->tenantEntityManager->flush();
        $bilan = $this->calcul->moyennesAnnuelles($inscription->getClasse())[$inscription->getId()] ?? null;

        return new JsonResponse($this->presenter($inscription, $this->calcul->decisionCalculee($bilan['moyenne'] ?? null), $bilan['moyenne'] ?? null));
    }

    private function verifier(Inscription $inscription): ?JsonResponse
    {
        if (StatutInscription::EnCours !== $inscription->getStatut() || !$inscription->getAnnee()->estModifiable()) {
            return new JsonResponse(['error' => 'Seule une inscription « en_cours » d\'une année non clôturée peut recevoir une décision.'], 409);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function presenter(Inscription $inscription, ?DecisionFinAnnee $calculee, ?float $moyenne): array
    {
        return [
            'inscription' => $inscription->getId(),
            'moyenneAnnuelle' => $moyenne,
            'decisionCalculee' => $calculee?->value,
            'decisionConseil' => $inscription->getDecision()?->value,
            'motif' => $inscription->getDecisionMotif(),
            'par' => $inscription->getDecisionPar(),
        ];
    }
}
