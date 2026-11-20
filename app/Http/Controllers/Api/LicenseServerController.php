<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Licensing\ActivationService;
use App\Licensing\LicenseException;
use App\Licensing\LicenseToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * License server for LabelDesk installs — /api/license/v1 (docs/licensing.md).
 * Errors: { "error": { "code", "message", "field" } }.
 */
class LicenseServerController extends Controller
{
    public function __construct(private ActivationService $activations, private LicenseToken $tokens) {}

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
        });
    }

    public function checkIn(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['token' => ['required', 'string', 'max:4000'], 'version' => ['nullable', 'string', 'max:20'], 'php_version' => ['nullable', 'string', 'max:20']]);

            return response()->json($this->activations->checkIn($data['token'], $data, $request->ip()));
        });
    }

    public function deactivate(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['token' => ['required', 'string', 'max:4000']]);
            $this->activations->deactivateInstall($data['token']);

            return response()->json(null, 204);
        });
    }

    public function publicKey(): JsonResponse
    {
        return response()->json(['alg' => 'EdDSA', 'crv' => 'Ed25519', 'kid' => $this->tokens->kid(), 'public_key' => $this->tokens->publicKeyBase64()])
            ->header('Cache-Control', 'public, max-age=3600');
    }

    private function run(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            $field = array_key_first($e->errors());

            return response()->json(['error' => ['code' => 'validation_failed', 'message' => $e->errors()[$field][0] ?? 'Invalid request.', 'field' => $field]], 422);
        } catch (LicenseException $e) {
            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'field' => $e->field]], $e->status);
        }
    }
}
