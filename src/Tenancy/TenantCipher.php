<?php

declare(strict_types=1);

namespace App\Tenancy;

/** Chiffre les mots de passe des bases d'école stockés dans la base centrale (libsodium secretbox). */
final class TenantCipher
{
    private readonly string $key;

    public function __construct(#[\SensitiveParameter] string $key)
    {
        $decoded = base64_decode($key, true);
        if (false === $decoded || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($decoded)) {
            throw new \InvalidArgumentException('TENANT_ENCRYPTION_KEY doit contenir 32 octets encodés en base64 (php -r "echo base64_encode(random_bytes(32));").');
        }
        $this->key = $decoded;
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Mot de passe de base d\'école illisible.');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if (false === $plain) {
            throw new \RuntimeException('Déchiffrement impossible : TENANT_ENCRYPTION_KEY a-t-elle changé ?');
        }

        return $plain;
    }
}
