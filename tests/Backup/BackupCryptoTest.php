<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupCrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Test unitaire rapide (sans base ni serveur) du chiffrement des sauvegardes. */
final class BackupCryptoTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function tailles(): iterable
    {
        yield 'vide' => [0];
        yield 'petit' => [100];
        yield 'exactement un bloc' => [65536];
        yield 'plusieurs blocs' => [200000];
    }

    #[DataProvider('tailles')]
    public function testAllerRetour(int $taille): void
    {
        [$source, $chiffre, $clair] = $this->fichiers();
        file_put_contents($source, $taille > 0 ? random_bytes($taille) : '');
        $crypto = new BackupCrypto(base64_encode(random_bytes(32)));

        $crypto->chiffrer($source, $chiffre);
        $crypto->dechiffrer($chiffre, $clair);

        self::assertFileEquals($source, $clair);
        $this->nettoyer($source, $chiffre, $clair);
    }

    public function testMauvaiseCleEtFichierAltere(): void
    {
        [$source, $chiffre, $clair] = $this->fichiers();
        file_put_contents($source, random_bytes(100000));
        $crypto = new BackupCrypto(base64_encode(random_bytes(32)));
        $crypto->chiffrer($source, $chiffre);

        try {
            (new BackupCrypto(base64_encode(random_bytes(32))))->dechiffrer($chiffre, $clair);
            self::fail('Une mauvaise clé doit être refusée.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $octets = (string) file_get_contents($chiffre);
        file_put_contents($chiffre, substr_replace($octets, chr(ord($octets[100]) ^ 1), 100, 1));
        try {
            $crypto->dechiffrer($chiffre, $clair);
            self::fail('Un fichier altéré doit être refusé.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        file_put_contents($chiffre, substr($octets, 0, 70000));
        try {
            $crypto->dechiffrer($chiffre, $clair);
            self::fail('Un fichier tronqué doit être refusé.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->nettoyer($source, $chiffre, $clair);
    }

    /** @return array{string, string, string} */
    private function fichiers(): array
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'bkt');

        return [$base.'.src', $base.'.enc', $base.'.out'];
    }

    private function nettoyer(string ...$fichiers): void
    {
        foreach ($fichiers as $fichier) {
            @unlink($fichier);
        }
    }
}
