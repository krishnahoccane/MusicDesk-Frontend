<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Licensing\ActivationService;
use App\Licensing\LicenseException;
use App\Licensing\LicenseService;
use App\Licensing\LicenseToken;
use App\Licensing\SecurityAlerts;
use App\Models\License;
use App\Models\LicenseAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * License server for LabelDesk installs — /api/license/v1 (docs/licensing.md).
 * Errors: { "error": { "code", "message", "field" } }.
 */
class LicenseServerController extends Controller
{
    /** Refusals worth a security alert (a normal "domain limit reached" is not). */
    private const ALERT_CODES = [
        'invalid_key', 'invalid_token', 'domain_blocked', 'domain_in_use', 'license_revoked', 'license_suspended',
        'license_expired', 'activation_not_found', 'activation_deactivated',
    ];

    public function __construct(private ActivationService $activations, private LicenseToken $tokens, private SecurityAlerts $alerts, private LicenseService $licenses) {}

    public function activate(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'key' => ['required', 'string', 'max:60'],
                'domain' => ['required', 'string', 'max:253'],
                'environment' => ['nullable', 'in:production,staging'],
                'version' => ['nullable', 'string', 'max:20'],
                'php_version' => ['nullable', 'string', 'max:20'],
                'instance_id' => ['nullable', 'string', 'max:100'],
            ]);

            return response()->json($this->activations->activateInstall($data['key'], $data, $request->ip()));
        }, fn (LicenseException $e) => $this->alertRefusal($e, $request, (string) $request->input('domain'), $this->licenses->findByKey((string) $request->input('key'))));
    }

    public function checkIn(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['token' => ['required', 'string', 'max:4000'], 'version' => ['nullable', 'string', 'max:20'], 'php_version' => ['nullable', 'string', 'max:20']]);

            return response()->json($this->activations->checkIn($data['token'], $data, $request->ip()));
        }, fn (LicenseException $e) => $this->alertRefusal($e, $request, ...$this->tokenHint((string) $request->input('token'))));
    }

    public function deactivate(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'token' => ['required', 'string', 'max:4000'],
                'code' => ['nullable', 'string', 'max:12'],
                'reason' => ['nullable', 'string', 'in:manual,transfer,key_change'],
            ]);
            $this->activations->deactivateInstall($data['token'], $data['code'] ?? null, $data['reason'] ?? null, $request->ip());

            return response()->json(null, 204);
        });
    }

    /** POST /deactivate/code: email the license owner a one-time code for /deactivate (LabelDesk 4.3.2+). */
    public function deactivationCode(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['token' => ['required', 'string', 'max:4000']]);

            return response()->json($this->activations->requestDeactivationCode($data['token'], $request->ip()));
        });
    }

    /** POST /api/license/v1/addons/activate: activate a LabelDesk add-on (plugin) with its own key. */
    public function activateAddon(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'key' => ['required', 'string', 'max:64'],
                'addon' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]{2,40}$/'],
                'domain' => ['required', 'string', 'max:253'],
                'version' => ['nullable', 'string', 'max:20'],
                'php_version' => ['nullable', 'string', 'max:20'],
                'instance_id' => ['nullable', 'string', 'max:100'],
            ]);

            return response()->json($this->activations->activateAddon($data['key'], $data['addon'], $data, $request->ip()));
        }, fn (LicenseException $e) => $this->alertRefusal($e, $request, (string) $request->input('domain'), $this->licenses->findByKey((string) $request->input('key'))));
    }

    /** POST /api/license/v1/addons/check-in */
    public function checkInAddon(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['token' => ['required', 'string', 'max:4000'], 'version' => ['nullable', 'string', 'max:20'], 'php_version' => ['nullable', 'string', 'max:20']]);

            return response()->json($this->activations->checkInAddon($data['token'], $data, $request->ip()));
        }, fn (LicenseException $e) => $this->alertRefusal($e, $request, ...$this->tokenHint((string) $request->input('token'))));
    }

    /** POST /api/license/v1/addons/deactivate → 204 */
    public function deactivateAddon(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['token' => ['required', 'string', 'max:4000']]);
            $this->activations->deactivateAddon($data['token']);

            return response()->json(null, 204);
        });
    }

    /**
     * POST /api/license/v1/report: security webhook. A LabelDesk install calls this
     * whenever its own license check fails (no license, bad signature, wrong domain,
     * revoked, tampered files...). The site admins are emailed and the domain shows
     * under Licenses > Status, where it can be issued a license or blocked.
     * The response tells the install whether to lock itself.
     */
    public function report(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'domain' => ['required', 'string', 'max:253'],
                'reason' => ['required', 'string', 'max:40'],
                'key' => ['nullable', 'string', 'max:60'],
                'token' => ['nullable', 'string', 'max:4000'],
                'version' => ['nullable', 'string', 'max:20'],
                'php_version' => ['nullable', 'string', 'max:20'],
                'instance_id' => ['nullable', 'string', 'max:100'],
                'message' => ['nullable', 'string', 'max:500'],
                'details' => ['nullable', 'array'],
            ]);
            $domain = ActivationService::normalise($data['domain']);
            if (! ActivationService::validDomain($domain)) {
                throw new LicenseException('invalid_domain', 'That is not a valid domain.', 422, 'domain');
            }

            // Which license (if any) the install claims to use. The key itself is never stored.
            $license = null;
            if (! empty($data['token'])) {
                try {
                    $claims = $this->tokens->verify($data['token'], allowExpired: true);
                    $license = License::query()->where('uuid', $claims['lic'] ?? '')->first();
                } catch (LicenseException) {
                    $data['reason'] = 'invalid_token';
                }
            }
            if (! $license && ! empty($data['key'])) {
                $license = $this->licenses->findByKey($data['key']);
            }
            unset($data['key'], $data['token']);

            $alert = $this->alerts->record($domain, $data['reason'], [...$data, 'ip' => $request->ip()], 'install', $license);
            $blocked = $alert?->status === LicenseAlert::BLOCKED;

            return response()->json([
                'received' => true,
                'action' => $blocked || $license?->status === License::REVOKED ? 'lock' : 'none',
                'message' => $blocked ? 'This domain is blocked from using '.setting('branding.product_name', 'LabelDesk').'.' : null,
            ], 202);
        });
    }

    public function publicKey(): JsonResponse
    {
        return response()->json(['alg' => 'EdDSA', 'crv' => 'Ed25519', 'kid' => $this->tokens->kid(), 'public_key' => $this->tokens->publicKeyBase64()])
            ->header('Cache-Control', 'public, max-age=3600');
    }

    private function run(callable $fn, ?callable $onRefused = null): JsonResponse
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            $field = array_key_first($e->errors());

            return response()->json(['error' => ['code' => 'validation_failed', 'message' => $e->errors()[$field][0] ?? 'Invalid request.', 'field' => $field]], 422);
        } catch (LicenseException $e) {
            if ($onRefused && in_array($e->errorCode, self::ALERT_CODES, true)) {
                $onRefused($e);
            }

            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'field' => $e->field]], $e->status);
        }
    }

    private function alertRefusal(LicenseException $e, Request $request, ?string $domain, ?License $license = null): void
    {
        if (! $domain) {
            return;
        }
        $this->alerts->record($domain, $e->errorCode, [
            'ip' => $request->ip(),
            'version' => $request->input('version'),
            'php_version' => $request->input('php_version'),
            'instance_id' => $request->input('instance_id'),
            'message' => 'Refused by the license server: '.$e->getMessage(),
        ], 'server', $license);
    }

    /**
     * The domain (and license) a check-in token claims, read without trusting it, so a
     * forged or revoked token can still be tied to a domain in the alert.
     *
     * @return array{0: ?string, 1: ?License}
     */
    private function tokenHint(string $token): array
    {
        $parts = explode('.', $token);
        $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        if (! is_array($claims) || ! is_string($claims['dom'] ?? null)) {
            return [null, null];
        }

        return [$claims['dom'], is_string($claims['lic'] ?? null) ? License::query()->where('uuid', $claims['lic'])->first() : null];
    }
}
