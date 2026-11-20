<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Plan;
use App\Services\Currency\CurrencyService;
use App\Services\Currency\RateUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CurrencyController extends Controller
{
    public function index(): View
    {
        return view('admin.currencies', [
            'currencies' => Currency::query()->orderByRaw("code = 'USD' desc")->orderBy('code')->get(),
            'plans' => Plan::active()->get(),
        ]);
    }

    public function update(Request $request, CurrencyService $service): RedirectResponse
    {
        foreach ((array) $request->input('currencies', []) as $code => $row) {
            $currency = Currency::findOrFail($code);
            validator($row, ['manual_rate' => 'nullable|numeric|gt:0', 'symbol' => 'required|string|max:8'])->validate();
            $currency->update([
                'symbol' => $row['symbol'],
                'manual_rate' => $row['manual_rate'] ?: null,
                'enabled' => $code === 'USD' ? true : (bool) ($row['enabled'] ?? false),
                'auto_update' => (bool) ($row['auto_update'] ?? false),
                'charm_pricing' => (bool) ($row['charm_pricing'] ?? false),
            ]);
        }
        $service->flush();

        return back()->with('toast', 'Currencies saved');
    }

    public function store(Request $request, CurrencyService $service): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha', 'size:3', 'unique:currencies,code'],
            'name' => ['required', 'string', 'max:60'],
            'symbol' => ['required', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'between:0,3'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);
        Currency::create(['code' => strtoupper($data['code'])] + $data + ['enabled' => true, 'auto_update' => true, 'charm_pricing' => true, 'rate_updated_at' => now()]);
        $service->flush();

        return back()->with('toast', strtoupper($data['code']).' added');
    }

    public function refresh(RateUpdater $updater): RedirectResponse
    {
        try {
            $updated = $updater->update();
        } catch (\Throwable $e) {
            return back()->with('toast', 'Rate update failed: '.$e->getMessage())->with('toast_tone', 'danger');
        }

        return back()->with('toast', count($updated).' exchange rates updated');
    }
}
