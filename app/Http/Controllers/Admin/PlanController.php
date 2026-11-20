<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Currency;
use App\Models\Plan;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        return view('admin.plans', [
            'plans' => Plan::orderBy('sort')->get(),
            'addons' => Addon::orderBy('sort')->get(),
            'currencies' => Currency::enabled()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Plan::create($this->planData($request) + ['sort' => (Plan::max('sort') ?? 0) + 1]);

        return back()->with('toast', 'Plan created');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->planData($request, $plan));

        return back()->with('toast', "{$plan->name} saved");
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        $plan->orders()->exists() ? $plan->update(['active' => false]) : $plan->delete();

        return back()->with('toast', 'Plan removed');
    }

    private function planData(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:60'],
            'blurb' => ['nullable', 'string', 'max:200'],
            'price_annual' => ['required', 'numeric', 'min:0'],
            'price_lifetime' => ['nullable', 'numeric', 'min:0'],
            'domains' => ['required', 'integer', 'min:1'],
            'extra_domain_price' => ['required', 'numeric', 'min:0'],
            'features' => ['nullable', 'string', 'max:3000'],
            'sort' => ['nullable', 'integer'],
        ]);
        $data['features'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $data['features'] ?? ''))));
        $data['featured'] = $request->boolean('featured');
        $data['active'] = $request->boolean('active');

        return $data;
    }

    public function updateAddons(Request $request): RedirectResponse
    {
        foreach ((array) $request->input('addons', []) as $id => $row) {
            $addon = Addon::findOrFail($id);
            validator($row, ['name' => 'required|string|max:80', 'price' => 'required|numeric|min:0', 'description' => 'nullable|string|max:300'])->validate();
            $addon->update([
                'name' => $row['name'], 'description' => $row['description'] ?? null, 'price' => $row['price'],
                'interval' => in_array($row['interval'] ?? null, ['month', 'year'], true) ? $row['interval'] : null,
                'checkout' => (bool) ($row['checkout'] ?? false), 'active' => (bool) ($row['active'] ?? false),
                'price_from' => (bool) ($row['price_from'] ?? false),
            ]);
        }

        return back()->with('toast', 'Add-ons saved');
    }

    public function updateCoupons(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate(['coupons' => ['nullable', 'string', 'max:2000'], 'annual_note' => ['nullable', 'string', 'max:300'], 'lifetime_note' => ['nullable', 'string', 'max:300']]);
        $settings->set(['pricing.coupons' => $data['coupons'], 'pricing.annual_note' => $data['annual_note'], 'pricing.lifetime_note' => $data['lifetime_note']]);

        return back()->with('toast', 'Coupons & notes saved');
    }
}
