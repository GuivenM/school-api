<?php

declare(strict_types=1);

namespace App\Backup;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Destinations des sauvegardes, activées par BACKUP_DESTINATIONS (liste séparée par des virgules) :
 *  - « local »  : dossier du serveur (BACKUP_LOCAL_DIR, relatif au projet ou absolu) ;
 *  - « remote » : stockage objet compatible S3 (AWS S3, Backblaze B2, Wasabi, Cloudflare R2, MinIO...).
 * Plusieurs destinations = chaque sauvegarde est copiée partout. Un dossier local sur le MÊME serveur ne protège pas d'une perte
 * du serveur : activer aussi « remote » (ou un montage réseau) en production.
 */
final class BackupStorages
{
    /** @var array<string, FilesystemOperator> */
    private array $systemes = [];

    /**
     * @param array{endpoint: string, region: string, bucket: string, key: string, secret: string, prefix: string, pathStyle: bool} $s3
     */
    public function __construct(
        private readonly string $destinations,
        private readonly string $dossierLocal,
        private readonly array $s3,
        private readonly string $racineProjet,
    ) {
    }

    /** @return list<string> */
    public function actives(): array
    {
        $noms = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($this->destinations))))));
        foreach ($noms as $nom) {
            if (!\in_array($nom, ['local', 'remote'], true)) {
                throw new \InvalidArgumentException(sprintf('BACKUP_DESTINATIONS : destination « %s » inconnue (local, remote).', $nom));
            }
        }

        return $noms;
    }

    public function ecrire(string $nom, string $chemin, string $fichierLocal): void
    {
        $flux = fopen($fichierLocal, 'rb');
        if (false === $flux) {
            throw new \RuntimeException('Fichier temporaire illisible.');
        }
        try {
            $this->systeme($nom)->writeStream($chemin, $flux);
        } finally {
            fclose($flux);
        }
    }

    public function lireVers(string $nom, string $chemin, string $fichierLocal): void
    {
        $flux = $this->systeme($nom)->readStream($chemin);
        $sortie = fopen($fichierLocal, 'wb');
        if (false === $sortie) {
            throw new \RuntimeException('Fichier temporaire non inscriptible.');
        }
        stream_copy_to_stream($flux, $sortie);
        fclose($sortie);
        fclose($flux);
    }

    public function supprimer(string $nom, string $chemin): void
    {
        if ($this->systeme($nom)->fileExists($chemin)) {
            $this->systeme($nom)->delete($chemin);
        }
    }

    private function systeme(string $nom): FilesystemOperator
    {
        return $this->systemes[$nom] ??= match ($nom) {
            'local' => new Filesystem(new LocalFilesystemAdapter($this->cheminLocal())),
            'remote' => $this->distant(),
            default => throw new \InvalidArgumentException(sprintf('Destination « %s » inconnue.', $nom)),
        };
    }

    private function cheminLocal(): string
    {
        $dossier = '' === trim($this->dossierLocal) ? 'var/backups' : trim($this->dossierLocal);

        return preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $dossier) ? $dossier : $this->racineProjet.'/'.$dossier;
    }

    private function distant(): FilesystemOperator
    {
        foreach (['bucket', 'key', 'secret'] as $champ) {
            if ('' === $this->s3[$champ]) {
                throw new \LogicException(sprintf('Stockage distant activé mais BACKUP_S3_%s est vide.', strtoupper($champ)));
            }
        }

        $config = [
            'version' => 'latest',
            'region' => '' === $this->s3['region'] ? 'auto' : $this->s3['region'],
            'use_path_style_endpoint' => $this->s3['pathStyle'],
            'credentials' => ['key' => $this->s3['key'], 'secret' => $this->s3['secret']],
        ];
        if ('' !== $this->s3['endpoint']) {
            $config['endpoint'] = $this->s3['endpoint'];
        }

        return new Filesystem(new AwsS3V3Adapter(new S3Client($config), $this->s3['bucket'], trim($this->s3['prefix'], '/')));
    }
}
