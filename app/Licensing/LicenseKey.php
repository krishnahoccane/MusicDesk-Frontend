<?php

namespace App\Licensing;

use SensitiveParameter;

/** LDSK-XXXX-XXXX-XXXX-XXXX keys: unambiguous characters (no 0/O/1/I/L), stored hashed + encrypted. */
final class LicenseKey
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function generate(string $prefix = 'LDSK'): string
    {
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $part = '';
            for ($i = 0; $i < 4; $i++) {
                $part .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $part;
        }

        return $prefix.'-'.implode('-', $groups);
    }

    public static function normalise(#[SensitiveParameter] string $key): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', trim($key)));
    }

    public static function hash(#[SensitiveParameter] string $key): string
    {
        return hash('sha256', self::normalise($key));
    }
}
