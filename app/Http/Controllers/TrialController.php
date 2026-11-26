<?php

namespace App\Http\Controllers;

use App\Licensing\LicenseException;
use App\Licensing\LicenseService;
use App\Models\Plan;
use App\Services\Orders\OrganisationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Free trial of a plan (when the plan offers one): no payment, one per customer account. */
class TrialController extends Controller
{
    public function show(Request $request, string $plan): View|RedirectResponse
    {
        $plan = Plan::active()->where('code', $plan)->firstOrFail();
        if (! $plan->offersTrial()) {
            return redirect()->route('checkout', $plan->code);
        }

        return view('marketing.trial', ['plan' => $plan, 'org' => $request->user()?->organisation, 'limits' => $plan->trialLimits()]);
    }

    public function store(Request $request, OrganisationService $orgs, LicenseService $licenses, string $plan): RedirectResponse
    {
        $plan = Plan::active()->where('code', $plan)->firstOrFail();
        abort_unless($plan->offersTrial(), 404);
        $guest = ! $request->user();
        if (! $guest && ! $request->user()->organisation) {
            return back()->withErrors(['trial' => 'Team accounts can’t start a trial. Sign in with a customer account.']);
        }

        $data = $request->validate([
            'name' => [$guest ? 'required' : 'nullable', 'string', 'max:120'],
            'email' => array_filter([$guest ? 'required' : 'nullable', 'email', 'max:190', $guest ? 'unique:users,email' : null]),
            'password' => [$guest ? 'required' : 'nullable', Password::min(10)],
            'company' => ['required', 'string', 'max:160'],
            'country' => ['required', 'string', 'size:2'],
            'terms' => ['accepted'],
        ], ['email.unique' => 'An account with this email exists. Log in first, then start the trial.']);

        if (! $guest && $request->user()->organisation->trial_used_at) {
            return back()->withErrors(['trial' => 'Your account has already used its free trial. Buy a license to keep using '.setting('branding.product_name', 'LabelDesk').'.']);
        }

        if ($guest) {
            $user = $orgs->register($data);
            Auth::login($user);
            $request->session()->regenerate();
        } else {
            $user = $request->user();
            $user->organisation->update(['name' => $data['company'], 'country' => strtoupper($data['country'])]);
        }

        try {
            [$license] = $licenses->createTrial($user->organisation->fresh(), $plan, LicenseService::actor($user));
        } catch (LicenseException $e) {
            return back()->withErrors(['trial' => $e->getMessage()]);
        }

        return redirect()->route('dashboard.licenses')->with('toast',
            "Your {$plan->trial_days}-day free trial of ".setting('branding.product_name', 'LabelDesk')." {$plan->name} has started ({$license->number}). Reveal the key below and enter it when you install.");
    }
}
