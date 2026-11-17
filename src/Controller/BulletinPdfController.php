<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiPlatform\Acces;
use App\Tenant\Calcul\BulletinPdf;
use App\Tenant\Calcul\BulletinService;
use App\Tenant\Entity\Classe;
use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\StatutInscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Bulletins en PDF (personnel de l'école).
 *  - avec ?periode=<id> : bulletin du trimestre ; sans : bulletin annuel ;
 *  - un trimestre non fermé donne un bulletin marqué « PROVISOIRE ».
 */
final class BulletinPdfController
{
    public function __construct(
        private readonly BulletinService $bulletins,
        private readonly BulletinPdf $pdf,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    #[Route('/api/inscriptions/{id}/bulletin.pdf', name: 'api_inscription_bulletin_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(Acces::PERSONNEL))]
    public function eleve(Inscription $inscription, Request $request): Response
    {
        $eleve = $inscription->getEleve();

        return $this->repondre([$this->bulletins->construire($inscription)], $request, sprintf('bulletin-%s-%s', $eleve->getNom(), $eleve->getPrenoms()));
    }

    #[Route('/api/classes/{id}/bulletins.pdf', name: 'api_classe_bulletins_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(Acces::PERSONNEL))]
    public function classe(Classe $classe, Request $request): Response
    {
        /** @var list<Inscription> $inscriptions */
        $inscriptions = $this->tenantEntityManager->createQuery(
            'SELECT i, e FROM '.Inscription::class.' i JOIN i.eleve e WHERE i.classe = :classe AND i.statut IN (:statuts) ORDER BY e.nom ASC, e.prenoms ASC, i.id ASC'
        )
            ->setParameter('classe', $classe)
            ->setParameter('statuts', [StatutInscription::EnCours->value, StatutInscription::Terminee->value])
            ->getResult();

        if ([] === $inscriptions) {
            return new JsonResponse(['error' => 'Aucun élève inscrit dans cette classe.'], 404);
        }

        return $this->repondre(array_map($this->bulletins->construire(...), $inscriptions), $request, 'bulletins-'.$classe->getLibelle());
    }

    /** @param list<array<string, mixed>> $bulletins */
    private function repondre(array $bulletins, Request $request, string $nomFichier): Response
    {
        $periodeId = $request->query->getInt('periode');

        try {
            if ($periodeId > 0) {
                if ([] === array_filter($bulletins[0]['periodes'], static fn (array $p): bool => $p['periode'] === $periodeId)) {
                    return new JsonResponse(['error' => 'Ce trimestre n\'appartient pas à l\'année scolaire de cette classe.'], 400);
                }
                $contenu = $this->pdf->trimestre($bulletins, $periodeId);
                $nomFichier .= '-t'.$periodeId;
            } else {
                $contenu = $this->pdf->annuel($bulletins);
                $nomFichier .= '-annuel';
            }
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        return new Response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, (new AsciiSlugger())->slug($nomFichier)->lower()->toString().'.pdf'),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
