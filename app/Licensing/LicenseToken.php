<?php

namespace App\Licensing;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Ed25519-signed JWTs for LabelDesk installs (alg EdDSA). The key pair is generated once and the
 * secret is stored APP_KEY-encrypted in settings. LabelDesk verifies tokens offline with the public
 * key from GET /api/license/v1/public-key.
 */
class LicenseToken
{
    private const SETTING = 'licensing.signing_key';

    public const TTL_DAYS = 30;

    public const OFFLINE_TTL_DAYS = 365;

    /** @param  array<string, mixed>  $claims */
    public function issue(array $claims, int $ttlDays = self::TTL_DAYS): string
    {
        $now = time();
        $header = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $this->kid()];
        $payload = $claims + ['iss' => rtrim((string) config('app.url'), '/'), 'iat' => $now, 'exp' => $now + $ttlDays * 86400];

        $signingInput = self::b64(json_encode($header)).'.'.self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $signingInput.'.'.self::b64(sodium_crypto_sign_detached($signingInput, $this->secretKey()));
    }

    /**
     * Verify signature and expiry. Returns the claims.
     *
     * @return array<string, mixed>
     */
    public function verify(string $token, bool $allowExpired = false): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new LicenseException('invalid_token', 'Malformed token.', 401);
        }
        [$h, $p, $s] = $parts;
        $signature = self::unb64($s);
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($signature, "$h.$p", $this->publicKey())) {
            throw new LicenseException('invalid_token', 'Token signature is not valid.', 401);
        }
        $claims = json_decode(self::unb64($p), true);
        if (! is_array($claims)) {
            throw new LicenseException('invalid_token', 'Malformed token.', 401);
        }
        if (! $allowExpired && ($claims['exp'] ?? 0) < time()) {
            throw new LicenseException('token_expired', 'Token expired. Activate again with the license key.', 401);
        }

        return $claims;
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
    }

    public function publicKeyBase64(): string
    {
        return base64_encode($this->publicKey());
    }

    public function kid(): string
    {
        return substr(hash('sha256', $this->publicKey()), 0, 16);
    }

    private function secretKey(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }

        $stored = Setting::query()->where('key', self::SETTING)->value('value');
        if (! $stored) {
            Cache::lock('licensing:signing-key', 10)->block(10, function () use (&$stored) {
                $stored = Setting::query()->where('key', self::SETTING)->value('value');
                if (! $stored) {
                    $stored = json_encode(Crypt::encryptString(base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))));
                    Setting::query()->create(['key' => self::SETTING, 'value' => $stored]);
                }
            });
        }

        $key = base64_decode(Crypt::decryptString(json_decode($stored, true)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The license signing key is invalid.');
        }

        return $secret = $key;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
