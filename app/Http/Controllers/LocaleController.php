<?php

namespace App\Http\Controllers;

use App\Services\Currency\CurrencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function currency(Request $request, CurrencyService $currencies): RedirectResponse
    {
        $code = strtoupper((string) $request->input('currency'));
        abort_unless($currencies->find($code), 422, 'Unsupported currency.');

        $request->session()->put('currency', $code);
        $request->user()?->update(['currency' => $code]);

        return back();
    }

    public function timezone(Request $request): RedirectResponse
    {
        $tz = (string) $request->input('timezone');
        abort_unless(in_array($tz, timezone_identifiers_list(), true), 422, 'Unknown time zone.');

        $request->session()->put('timezone', $tz);
        $request->user()?->update(['timezone' => $tz]);

        return back()->with('toast', "Times now shown in $tz");
    }
}
