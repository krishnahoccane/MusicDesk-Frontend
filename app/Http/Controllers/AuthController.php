<?php

namespace App\Http\Controllers;

use App\Services\Orders\OrganisationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Those details don’t match an account.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(Auth::user()->is_admin && ! Auth::user()->organisation_id ? route('admin.dashboard') : route('dashboard'));
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    /** Creates the user and their organisation. */
    public function register(Request $request, OrganisationService $orgs): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'intent' => ['nullable', 'string', 'max:120'],
            'password' => ['required', Password::min(10)],
        ]);

        $user = $orgs->register($data);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('toast', 'Welcome! Your organisation is set up.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
