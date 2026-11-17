<?php

declare(strict_types=1);

namespace App\Tenant\Calcul;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Génère les bulletins en PDF (A4) à partir des gabarits de templates/bulletin. Plusieurs élèves = un seul PDF, une page chacun. */
final class BulletinPdf
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/bulletin')]
        private readonly string $dossierGabarits,
        #[Autowire('%kernel.project_dir%/var/dompdf')]
        private readonly string $dossierTemporaire,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $bulletins issus de BulletinService::construire()
     */
    public function trimestre(array $bulletins, int $periodeId): string
    {
        $pages = [];
        foreach ($bulletins as $bulletin) {
            foreach ($bulletin['periodes'] as $bloc) {
                if ($bloc['periode'] === $periodeId) {
                    $pages[] = $this->gabarit('trimestre.html.php', ['b' => $bulletin, 'bloc' => $bloc, 'provisoire' => 'ouverte' === $bloc['statut']]);
                }
            }
        }

        return $this->pdf($pages);
    }

    /** @param list<array<string, mixed>> $bulletins */
    public function annuel(array $bulletins): string
    {
        return $this->pdf(array_map(fn (array $b): string => $this->gabarit('annuel.html.php', [
            'b' => $b,
            'provisoire' => [] !== array_filter($b['periodes'], static fn (array $p): bool => 'ouverte' === $p['statut']),
        ]), $bulletins));
    }

    /** @param array<string, mixed> $variables */
    private function gabarit(string $fichier, array $variables): string
    {
        extract($variables, \EXTR_SKIP);
        ob_start();
        include $this->dossierGabarits.'/'.$fichier;

        return (string) ob_get_clean();
    }

    /** @param list<string> $pages */
    private function pdf(array $pages): string
    {
        if ([] === $pages) {
            throw new \DomainException('Aucun bulletin à produire pour ces critères.');
        }

        if (!is_dir($this->dossierTemporaire)) {
            @mkdir($this->dossierTemporaire, 0775, true);
        }

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('tempDir', $this->dossierTemporaire);
        $options->set('fontCache', $this->dossierTemporaire);

        $css = file_get_contents($this->dossierGabarits.'/bulletin.css');
        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><style>'.$css.'</style></head><body>'
            .implode('<div class="saut"></div>', $pages).'</body></html>';

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
