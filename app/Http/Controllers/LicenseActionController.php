<?php

namespace App\Http\Controllers;

use App\Licensing\ActivationService;
use App\Licensing\LicenseException;
use App\Licensing\LicenseService;
use App\Models\License;
use App\Models\LicenseActivation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer portal license actions (JSON, called from the licenses page), plus the
 * license certificate and offline activation file downloads.
 */
class LicenseActionController extends Controller
{
    public function __construct(private LicenseService $licenses, private ActivationService $activations) {}

    public function reveal(Request $request, License $license): JsonResponse
    {
        $this->own($request, $license);

        return response()->json(['key' => $this->licenses->revealKey($license, LicenseService::actor($request->user()))])
            ->header('Cache-Control', 'no-store');
    }

    public function addDomain(Request $request, License $license): JsonResponse
    {
        $this->own($request, $license, manage: true);
        $data = $request->validate(['domain' => ['required', 'string', 'max:253'], 'environment' => ['nullable', 'in:production,staging']]);

        return $this->run(function () use ($request, $license, $data) {
            $a = $this->activations->add($license, $data['domain'], $data['environment'] ?? null, LicenseService::actor($request->user()));

            return ['message' => "{$a->domain} added".($a->environment === 'staging' ? ' as a staging domain (free)' : ''), 'activation' => ['id' => $a->uuid, 'domain' => $a->domain]];
        });
    }

    public function moveDomain(Request $request, License $license, string $activation): JsonResponse
    {
        $this->own($request, $license, manage: true);
        $data = $request->validate(['new_domain' => ['required', 'string', 'max:253']]);

        return $this->run(function () use ($request, $license, $activation, $data) {
            $new = $this->activations->move($this->activation($license, $activation), $data['new_domain'], LicenseService::actor($request->user()));

            return ['message' => "Moved to {$new->domain}. The old domain stops working at its next check-in."];
        });
    }

    public function deactivateDomain(Request $request, License $license, string $activation): JsonResponse
    {
        $this->own($request, $license, manage: true);

        return $this->run(function () use ($request, $license, $activation) {
            $this->activations->deactivate($this->activation($license, $activation), LicenseService::actor($request->user()));

            return ['message' => 'Domain deactivated and slot freed'];
        });
    }

    public function checkIn(Request $request, License $license, string $activation): JsonResponse
    {
        $this->own($request, $license, manage: true);

        return $this->run(function () use ($request, $license, $activation) {
            $this->activations->requestCheckIn($this->activation($license, $activation), LicenseService::actor($request->user()));

            return ['message' => 'Check-in requested. The install reports on its next contact.'];
        });
    }

    public function autoRenew(Request $request, License $license): JsonResponse
    {
        $this->own($request, $license, roles: ['owner', 'admin', 'billing']);
        $data = $request->validate(['auto_renew' => ['required', 'boolean']]);

        return $this->run(function () use ($request, $license, $data) {
            $this->licenses->setAutoRenew($license, (bool) $data['auto_renew'], LicenseService::actor($request->user()));

            return ['message' => $data['auto_renew'] ? 'Renewal reminders on' : 'Renewal reminders off'];
        });
    }

    /** License certificate (PDF). */
    public function certificate(Request $request, License $license): Response
    {
        $this->own($request, $license);

        return Pdf::loadView('licenses.certificate', ['license' => $license->load('plan', 'organisation')])->setPaper('a4', 'landscape')
            ->download("{$license->number}-certificate.pdf");
    }

    /**
     * Offline activation: the customer uploads the request file LabelDesk exports
     * ({ "domain", "instance_id" } JSON) and gets back a signed activation file valid for 365 days.
     */
    public function offlineActivation(Request $request, License $license): Response
    {
        $this->own($request, $license, manage: true);
        $request->validate(['request_file' => ['required', 'file', 'max:64']]);
        $payload = json_decode((string) file_get_contents($request->file('request_file')->getRealPath()), true);

        if (! is_array($payload) || empty($payload['domain'])) {
            return back()->with(['toast' => 'That isn’t a LabelDesk activation request file.', 'toast_tone' => 'danger']);
        }
        try {
            $result = $this->activations->offlineActivation($license, (string) $payload['domain'], isset($payload['instance_id']) ? (string) $payload['instance_id'] : null, LicenseService::actor($request->user()));
        } catch (LicenseException $e) {
            return back()->with(['toast' => $e->getMessage(), 'toast_tone' => 'danger']);
        }

        $file = json_encode(['format' => 'ldlic-1', 'license' => $license->number, 'domain' => $result['activation']->domain, 'token' => $result['token']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return response($file, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="activation-'.$result['activation']->domain.'.ldlic"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function run(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (LicenseException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => $e->errorCode], $e->status >= 400 && $e->status < 500 ? $e->status : 409);
        }
    }

    private function own(Request $request, License $license, bool $manage = false, ?array $roles = null): void
    {
        $user = $request->user();
        abort_unless($user->organisation_id && $user->organisation_id === $license->organisation_id, 404);
        $roles ??= $manage ? ['owner', 'admin', 'technical'] : null;
        if ($roles && ! in_array($user->role, $roles, true)) {
            abort(403, 'Your role can’t change licenses. Ask an owner or admin.');
        }
    }

    private function activation(License $license, string $id): LicenseActivation
    {
        return $license->activations()->where('uuid', $id)->where('status', '!=', LicenseActivation::DEACTIVATED)->first()
            ?? throw new LicenseException('activation_not_found', 'That domain isn’t on this license.', 404);
    }
}
