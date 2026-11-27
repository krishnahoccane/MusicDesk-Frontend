<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Licensing\ActivationService;
use App\Licensing\LicenseService;
use App\Licensing\LicenseToken;
use App\Models\License;
use App\Models\LicenseActivation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * "Sign in to site": open a customer's LabelDesk install as its active Super Admin, for support.
 * MusicDesk.tech signs a single-use token (EdDSA, same key as license tokens, valid 2 minutes) for
 * that domain; the browser POSTs it to https://<domain>/musicdesk/sso, where LabelDesk 4.3.4+
 * verifies it with the public key it ships with and signs in (docs/labeldesk-changes-2.0.2.md).
 * Works for any domain, also unlicensed or failing ones, since LabelDesk verifies offline.
 */
class RemoteLoginController extends Controller
{
    public const TTL_SECONDS = 120;

    public const PATH = '/musicdesk/sso';

    public function __invoke(Request $request, LicenseToken $tokens, LicenseService $licenses): View
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'license' => ['nullable', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $domain = ActivationService::normalise($data['domain']);
        abort_unless(ActivationService::validDomain($domain) || self::isLocal($domain), 422, 'Not a valid domain.');

        $license = ! empty($data['license']) ? License::query()->where('uuid', $data['license'])->first() : null;
        $activation = $license ? LicenseActivation::query()->where('license_id', $license->id)->where('domain', $domain)->latest('id')->first() : null;
        $admin = $request->user();

        $token = $tokens->issueFor([
            'typ' => 'admin_sso',
            'aud' => $domain,
            'dom' => $domain,
            'lic' => $license?->uuid,
            'act' => $activation?->uuid,
            'addon' => $license?->addon?->code,
            'jti' => (string) Str::uuid(),
            'by' => $admin->name,
            'by_email' => $admin->email,
            'reason' => $data['reason'] ?? null,
        ], self::TTL_SECONDS);

        if ($license) {
            $licenses->event($license, 'remote_login', ['domain' => $domain, 'by' => $admin->name], LicenseService::actor($admin));
        }
        Log::notice('Admin signed in to a LabelDesk site', ['admin' => $admin->email, 'domain' => $domain, 'license' => $license?->number]);

        return view('admin.remote-login', ['url' => self::urlFor($domain), 'token' => $token, 'domain' => $domain]);
    }

    public static function urlFor(string $domain): string
    {
        return (self::isLocal($domain) ? 'http' : 'https').'://'.$domain.self::PATH;
    }

    private static function isLocal(string $domain): bool
    {
        return $domain === 'localhost' || str_ends_with($domain, '.localhost') || str_ends_with($domain, '.test') || preg_match('/^127\.\d+\.\d+\.\d+(:\d+)?$/', $domain) === 1;
    }
}
