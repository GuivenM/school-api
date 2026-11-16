<?php

declare(strict_types=1);

namespace App\Backup;

use Doctrine\DBAL\Connection;
use Symfony\Component\Process\Process;

/**
 * Export / import SQL d'une base d'école avec les outils officiels (mysqldump, mysql). Les identifiants passent par un fichier
 * temporaire à droits restreints (jamais en ligne de commande) ; l'export est compressé au fil de l'eau.
 * Les binaires doivent être installés sur le serveur (BACKUP_MYSQLDUMP_BINARY / BACKUP_MYSQL_BINARY : nom ou chemin complet).
 */
final class MysqlTools
{
    public function __construct(
        private readonly Connection $landlordConnection,
        private readonly string $binaireDump,
        private readonly string $binaireMysql,
    ) {
    }

    public function exporter(string $base, string $utilisateur, #[\SensitiveParameter] string $motDePasse, string $fichierGz): void
    {
        $options = $this->fichierOptions($utilisateur, $motDePasse);
        $gz = gzopen($fichierGz, 'wb6');
        if (false === $gz) {
            throw new \RuntimeException('Impossible de créer le fichier de sauvegarde.');
        }

        try {
            $processus = new Process([
                $this->binaireDump, '--defaults-extra-file='.$options, '--single-transaction', '--quick', '--no-tablespaces',
                '--routines', '--triggers', '--hex-blob', '--default-character-set=utf8mb4', $base,
            ]);
            $processus->setTimeout(1800);
            $processus->run(static function (string $type, string $donnees) use ($gz): void {
                if (Process::OUT === $type) {
                    gzwrite($gz, $donnees);
                }
            });
            if (!$processus->isSuccessful()) {
                throw new \RuntimeException('mysqldump a échoué : '.trim($processus->getErrorOutput()));
            }
        } finally {
            gzclose($gz);
            @unlink($options);
        }
    }

    public function importer(string $base, string $utilisateur, #[\SensitiveParameter] string $motDePasse, string $fichierGz): void
    {
        $options = $this->fichierOptions($utilisateur, $motDePasse);
        $flux = fopen('compress.zlib://'.$fichierGz, 'rb');
        if (false === $flux) {
            throw new \RuntimeException('Sauvegarde illisible.');
        }

        try {
            $processus = new Process([$this->binaireMysql, '--defaults-extra-file='.$options, '--default-character-set=utf8mb4', $base]);
            $processus->setTimeout(1800);
            $processus->setInput($flux);
            $processus->run();
            if (!$processus->isSuccessful()) {
                throw new \RuntimeException('mysql (import) a échoué : '.trim($processus->getErrorOutput()));
            }
        } finally {
            fclose($flux);
            @unlink($options);
        }
    }

    private function fichierOptions(string $utilisateur, string $motDePasse): string
    {
        $parametres = $this->landlordConnection->getParams();
        $echapper = static fn (string $v): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"';

        $lignes = ['[client]', 'user='.$echapper($utilisateur), 'password='.$echapper($motDePasse)];
        if (!empty($parametres['unix_socket'])) {
            $lignes[] = 'socket='.$echapper((string) $parametres['unix_socket']);
        } else {
            $lignes[] = 'host='.$echapper((string) ($parametres['host'] ?? '127.0.0.1'));
            $lignes[] = 'port='.(int) ($parametres['port'] ?? 3306);
        }

        $fichier = tempnam(sys_get_temp_dir(), 'sco');
        if (false === $fichier) {
            throw new \RuntimeException('Fichier temporaire impossible à créer.');
        }
        chmod($fichier, 0600);
        file_put_contents($fichier, implode("\n", $lignes)."\n");

        return $fichier;
    }
}
