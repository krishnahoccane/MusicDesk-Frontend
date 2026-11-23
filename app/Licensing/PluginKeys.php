<?php

namespace App\Licensing;

use RuntimeException;

/**
 * The Ed25519 key pair LabelDesk trusts for add-on and release packages (config/plugins.php):
 * the public half is compiled into LabelDesk, the secret half is a file on this server.
 */
final class PluginKeys
{
    public static function publicKey(): string
    {
        $key = base64_decode((string) config('plugins.public_key'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('config plugins.public_key is not a base64 Ed25519 public key.');
        }

        return $key;
    }

    /** Key id LabelDesk shows in signature.json: first 16 hex chars of sha256(public key). */
    public static function kid(): string
    {
        return substr(hash('sha256', self::publicKey()), 0, 16);
    }

    public static function canSign(): bool
    {
        return is_file((string) config('plugins.signing_key_path'));
    }

    /** The secret key (caller wipes it with sodium_memzero). */
    public static function secretKey(string $missingMessage): string
    {
        $path = (string) config('plugins.signing_key_path');
        if (! is_file($path)) {
            throw new RuntimeException($missingMessage);
        }
        $secret = base64_decode(trim((string) file_get_contents($path)), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The plugin signing key file is not a base64 Ed25519 secret key.');
        }
        if (! hash_equals(self::publicKey(), sodium_crypto_sign_publickey_from_secretkey($secret))) {
            throw new RuntimeException('The plugin signing key does not match the public key LabelDesk trusts (config plugins.public_key).');
        }

        return $secret;
    }
}
