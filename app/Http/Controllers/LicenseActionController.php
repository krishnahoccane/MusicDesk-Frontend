<?php

namespace App\Http\Controllers;

use App\Services\Billing\BillingClient;
use App\Services\Billing\BillingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Customer portal → billing.twsd.me license actions (JSON, called from the licenses page).
 * {license} is billing's public id (lic_…), {activation} is act_… .
 */
class LicenseActionController extends Controller
{
    public function __construct(private BillingClient $billing) {}

    public function reveal(Request $request, string $license): JsonResponse
    {
        return $this->run($request, $license, fn ($org, $user) => ['key' => $this->billing->revealKey($org, $license, $user)], manage: false)
            ->header('Cache-Control', 'no-store');
    }

    public function addDomain(Request $request, string $license): JsonResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:253'], 'environment' => ['nullable', 'in:production,staging']]);

        return $this->run($request, $license, fn ($org, $user) => ['activation' => $this->billing->addActivation($org, $license, $data['domain'], $data['environment'] ?? null, $user), 'message' => 'Domain added']);
    }

    public function moveDomain(Request $request, string $license, string $activation): JsonResponse
    {
        $data = $request->validate(['new_domain' => ['required', 'string', 'max:253']]);

        return $this->run($request, $license, fn ($org, $user) => ['activation' => $this->billing->moveActivation($org, $license, $activation, $data['new_domain'], $user), 'message' => 'Domain moved. The old domain goes read-only once the new one checks in.']);
    }

    public function deactivateDomain(Request $request, string $license, string $activation): JsonResponse
    {
        return $this->run($request, $license, function ($org, $user) use ($license, $activation) {
            $this->billing->deactivateActivation($org, $license, $activation, $user);

            return ['message' => 'Domain deactivated and slot freed'];
        });
    }

    public function checkIn(Request $request, string $license, string $activation): JsonResponse
    {
        return $this->run($request, $license, function ($org, $user) use ($license, $activation) {
            $this->billing->requestCheckIn($org, $license, $activation, $user);

            return ['message' => 'Check-in requested. The install will report on its next contact.'];
        });
    }

    public function autoRenew(Request $request, string $license): JsonResponse
    {
        $data = $request->validate(['auto_renew' => ['required', 'boolean']]);

        return $this->run($request, $license, function ($org, $user) use ($license, $data) {
            $this->billing->setAutoRenew($org, $license, (bool) $data['auto_renew'], $user);

            return ['message' => $data['auto_renew'] ? 'Auto-renew turned on' : 'Auto-renew turned off'];
        }, role: ['owner', 'admin', 'billing']);
    }

    /** Ownership + role checks, then the billing call with customer-safe errors. */
    private function run(Request $request, string $license, callable $fn, bool $manage = true, array $role = ['owner', 'admin', 'technical']): JsonResponse
    {
        $user = $request->user();
        $org = \App\Models\Organisation::find($user->organisation_id);
        abort_unless($org, 404);

        if ($manage && ! in_array($user->role, $role, true)) {
            return response()->json(['error' => 'Your role can’t change licenses. Ask an owner or admin.'], 403);
        }

        // The license must be one of this organisation's (billing also enforces this via X-Customer-Id).
        try {
            $owned = collect($this->billing->licenses($org))->contains('billing_id', $license);
        } catch (\Throwable) {
            $owned = false;
        }
        if (! $owned) {
            return response()->json(['error' => 'License not found.'], 404);
        }

        try {
            return response()->json($fn($org, $user));
        } catch (BillingException $e) {
            return response()->json(['error' => $e->customerMessage(), 'code' => $e->errorCode], $e->status >= 400 && $e->status < 500 ? $e->status : 502);
        } catch (\Throwable $e) {
            Log::error('License action failed', ['license' => $license, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'The licensing service is unavailable. Please try again shortly.'], 503);
        }
    }
}
