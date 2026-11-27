<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * "Sign in as customer": a team member (permission "Sign in as customers") opens the customer portal
 * as the organisation's owner. The portal shows a banner with "Return to admin"; nothing else changes.
 */
class ImpersonationController extends Controller
{
    public const SESSION = 'impersonator_id';

    public function start(Request $request, Organisation $organisation): RedirectResponse
    {
        $admin = $request->user();
        $user = $organisation->users()->where('is_admin', false)->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 else 2 end")->orderBy('id')->first();
        if (! $user) {
            return back()->with(['toast' => "{$organisation->name} has no user account to sign in as.", 'toast_tone' => 'danger']);
        }

        Log::notice('Admin signed in as customer', ['admin' => $admin->email, 'customer' => $user->email, 'organisation' => $organisation->id]);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, $admin->id);
        $request->session()->put('impersonator_return', route('admin.customers.show', $organisation));

        return redirect()->route('dashboard')->with('toast', "Signed in as {$user->name} ({$organisation->name})");
    }

    /** Back to the team member's own account. */
    public function stop(Request $request): RedirectResponse
    {
        $admin = User::query()->whereKey($request->session()->pull(self::SESSION))->where('is_admin', true)->first();
        $return = $request->session()->pull('impersonator_return');
        abort_unless($admin, 403);

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->to($return ?: route('admin.customers'))->with('toast', 'Back in your admin account');
    }
}
