<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Licensing\ActivationService;
use App\Licensing\LicenseService;
use App\Licensing\SecurityAlerts;
use App\Models\LicenseActivation;
use App\Models\LicenseAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Licenses › Status: every live domain, and security alerts from failed license checks. */
class LicenseStatusController extends Controller
{
    public function __construct(private SecurityAlerts $alerts) {}

    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q'));
        $state = $request->query('alerts', 'open');
        $now = now();

        $alerts = LicenseAlert::query()->with('license.organisation', 'license.addon')->search($term)
            ->when($state !== 'all', fn ($q) => $q->where('status', in_array($state, [LicenseAlert::OPEN, LicenseAlert::BLOCKED, LicenseAlert::ISSUED, LicenseAlert::DISMISSED], true) ? $state : LicenseAlert::OPEN))
            ->latest('last_seen_at')->paginate(15, ['*'], 'apage')->withQueryString();

        // Which alert domains are live on a license (so the row offers "Open license" instead of "Issue license").
        $live = LicenseActivation::query()->with('license.organisation', 'license.addon')->whereIn('domain', $alerts->pluck('domain'))
            ->where('status', '!=', LicenseActivation::DEACTIVATED)->get()->keyBy('domain');

        $env = $request->query('env');
        $domains = LicenseActivation::query()->with('license.organisation', 'license.plan', 'license.addon')
            ->where('status', '!=', LicenseActivation::DEACTIVATED)
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('domain', 'like', "%{$term}%")
                ->orWhereHas('license', fn ($l) => $l->where('number', 'like', "%{$term}%")->orWhereHas('organisation', fn ($o) => $o->where('name', 'like', "%{$term}%")))))
            ->when(in_array($env, ['production', 'staging'], true), fn ($q) => $q->where('environment', $env))
            ->when($request->query('online') === '1', fn ($q) => $q->where('status', LicenseActivation::ACTIVE)->where('last_check_in_at', '>', $now->copy()->subHours(LicenseActivation::ONLINE_HOURS)))
            ->orderByRaw('last_check_in_at is null')->latest('last_check_in_at')
            ->paginate(25, ['*'], 'dpage')->withQueryString();

        return view('admin.licenses.status', [
            'alerts' => $alerts,
            'live' => $live,
            'domains' => $domains,
            'state' => $state,
            'stats' => [
                'domains' => LicenseActivation::query()->where('status', '!=', LicenseActivation::DEACTIVATED)->count(),
                'online' => LicenseActivation::query()->where('status', LicenseActivation::ACTIVE)->where('last_check_in_at', '>', $now->copy()->subHours(LicenseActivation::ONLINE_HOURS))->count(),
                'silent' => LicenseActivation::query()->where('status', LicenseActivation::ACTIVE)->where(fn ($q) => $q->whereNull('last_check_in_at')->orWhere('last_check_in_at', '<=', $now->copy()->subDays(7)))->count(),
                'open' => LicenseAlert::query()->where('status', LicenseAlert::OPEN)->count(),
                'failures24' => LicenseAlert::query()->where('last_seen_at', '>', $now->copy()->subDay())->count(),
                'blocked' => LicenseAlert::query()->where('status', LicenseAlert::BLOCKED)->count(),
            ],
        ]);
    }

    public function block(Request $request, LicenseAlert $alert): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->alerts->block($alert, LicenseService::actor($request->user()), $data['note'] ?? null);

        return back()->with('toast', "{$alert->domain} blocked. It can’t activate or check in until you unblock it.");
    }

    public function blockDomain(Request $request): RedirectResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:253'], 'note' => ['nullable', 'string', 'max:500']]);
        $domain = ActivationService::normalise($data['domain']);
        if (! ActivationService::validDomain($domain)) {
            return back()->withErrors(['domain' => 'Enter a domain like portal.example.com.'])->withInput();
        }
        $this->alerts->blockDomain($domain, LicenseService::actor($request->user()), $data['note'] ?? null);

        return back()->with('toast', "{$domain} blocked.");
    }

    public function update(Request $request, LicenseAlert $alert): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([LicenseAlert::DISMISSED, LicenseAlert::OPEN])], 'note' => ['nullable', 'string', 'max:500']]);
        $actor = LicenseService::actor($request->user());

        if ($data['status'] === LicenseAlert::OPEN) {
            $alert->update(['status' => LicenseAlert::OPEN, 'resolved_at' => null, 'resolved_by' => null]);

            return back()->with('toast', "{$alert->domain} reopened.");
        }
        $wasBlocked = $alert->status === LicenseAlert::BLOCKED;
        $this->alerts->resolve($alert, LicenseAlert::DISMISSED, $actor, note: $data['note'] ?? null);

        return back()->with('toast', $wasBlocked ? "{$alert->domain} unblocked." : "Alert for {$alert->domain} dismissed. It reopens if the domain fails again.");
    }
}
