<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Chiffre les sauvegardes avant qu'elles quittent le serveur (données d'élèves mineurs, éventuellement sur un cloud tiers).
 * libsodium « secretstream » (XChaCha20-Poly1305) par blocs de 64 Ko : fonctionne sur de gros fichiers sans les charger en mémoire,
 * détecte toute altération ou troncature. Format : « SCB1 » + en-tête (24 octets) + blocs chiffrés.
 * La clé (BACKUP_ENCRYPTION_KEY) doit être conservée À PART : sans elle, aucune sauvegarde n'est relisible.
 */
final class BackupCrypto
{
    private const MAGIE = 'SCB1';
    private const BLOC = 65536;

    private readonly string $cle;

    public function __construct(#[\SensitiveParameter] string $cle)
    {
        $decodee = base64_decode($cle, true);
        if (false === $decodee || \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES !== \strlen($decodee)) {
            throw new \InvalidArgumentException('BACKUP_ENCRYPTION_KEY doit contenir 32 octets encodés en base64 (php -r "echo base64_encode(random_bytes(32));").');
        }
        $this->cle = $decodee;
    }

    public function chiffrer(string $source, string $destination): void
    {
        $entree = $this->ouvrir($source, 'rb');
        $sortie = $this->ouvrir($destination, 'wb');

        [$etat, $entete] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->cle);
        fwrite($sortie, self::MAGIE.$entete);

        $bloc = (string) fread($entree, self::BLOC);
        do {
            $suivant = (string) fread($entree, self::BLOC);
            $etiquette = '' === $suivant
                ? \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($sortie, sodium_crypto_secretstream_xchacha20poly1305_push($etat, $bloc, '', $etiquette));
            $bloc = $suivant;
        } while ('' !== $bloc);

        fclose($entree);
        fclose($sortie);
    }

    public function dechiffrer(string $source, string $destination): void
    {
        $entree = $this->ouvrir($source, 'rb');
        $sortie = $this->ouvrir($destination, 'wb');

        $debut = (string) fread($entree, \strlen(self::MAGIE) + \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if (!str_starts_with($debut, self::MAGIE)) {
            throw new \RuntimeException('Fichier de sauvegarde illisible (format inconnu).');
        }
        $etat = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($debut, \strlen(self::MAGIE)), $this->cle);

        $final = false;
        while (!$final && !feof($entree)) {
            $bloc = (string) fread($entree, self::BLOC + \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ('' === $bloc) {
                break;
            }
            $clair = sodium_crypto_secretstream_xchacha20poly1305_pull($etat, $bloc);
            if (false === $clair) {
                throw new \RuntimeException('Déchiffrement impossible : mauvaise clé BACKUP_ENCRYPTION_KEY ou fichier altéré.');
            }
            fwrite($sortie, $clair[0]);
            $final = \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $clair[1];
        }
        if (!$final) {
            throw new \RuntimeException('Sauvegarde tronquée : fin de fichier manquante.');
        }

        fclose($entree);
        fclose($sortie);
    }

    /** @return resource */
    private function ouvrir(string $chemin, string $mode)
    {
        $ressource = @fopen($chemin, $mode);
        if (false === $ressource) {
            throw new \RuntimeException(sprintf('Impossible d\'ouvrir %s.', $chemin));
        }

        return $ressource;
    }
}
