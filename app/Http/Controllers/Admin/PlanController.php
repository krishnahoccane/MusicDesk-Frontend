<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Licensing\LicenseService;
use App\Models\Currency;
use App\Models\License;
use App\Models\LicenseAddon;
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
            'licenseAddons' => LicenseAddon::with('plans')->withCount(['licenses as keys_count' => fn ($q) => $q->where('status', '!=', 'revoked')])->orderBy('sort')->orderBy('name')->get(),
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

    // ------------------------------------------------------------ licensed add-ons

    public function storeLicenseAddon(Request $request, LicenseService $licenses): RedirectResponse
    {
        [$data, $plans] = $this->licenseAddonData($request);
        $addon = LicenseAddon::create($data + ['sort' => (int) LicenseAddon::max('sort') + 1]);
        $addon->plans()->sync($plans);
        $issued = $this->issueToExisting($request, $addon, $licenses);

        return back()->with('toast', "Add-on {$addon->name} created".($issued ? " · issued to {$issued} existing ".($issued === 1 ? 'license' : 'licenses') : ''));
    }

    public function updateLicenseAddon(Request $request, LicenseAddon $addon, LicenseService $licenses): RedirectResponse
    {
        [$data, $plans] = $this->licenseAddonData($request, $addon);
        $addon->update($data);
        $addon->plans()->sync($plans);
        $issued = $this->issueToExisting($request, $addon, $licenses);

        return back()->with('toast', "{$addon->name} saved".($issued ? " · issued to {$issued} existing ".($issued === 1 ? 'license' : 'licenses') : ''));
    }

    public function destroyLicenseAddon(LicenseAddon $addon): RedirectResponse
    {
        if ($addon->licenses()->exists() || $addon->releases()->exists()) {
            $addon->update(['active' => false]);

            return back()->with('toast', "{$addon->name} has keys or releases, so it was hidden instead of deleted");
        }
        $addon->delete();

        return back()->with('toast', 'Add-on deleted');
    }

    /** @return array{0: array, 1: int[]} */
    private function licenseAddonData(Request $request, ?LicenseAddon $addon = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('license_addons', 'code')->ignore($addon?->id)],
            'description' => ['nullable', 'string', 'max:300'],
            'price_annual' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'price_lifetime' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'plans' => ['nullable', 'array'],
            'plans.*' => ['integer', 'exists:plans,id'],
        ]);

        return [[
            'name' => $data['name'], 'code' => strtolower($data['code']), 'description' => $data['description'] ?? null,
            'price_annual' => $data['price_annual'], 'price_lifetime' => $data['price_lifetime'] ?? null, 'active' => $request->boolean('active'),
        ], array_map('intval', $data['plans'] ?? [])];
    }

    /** Optional: give the add-on key now to live licenses on the plans that include it (otherwise at their next purchase or change). */
    private function issueToExisting(Request $request, LicenseAddon $addon, LicenseService $licenses): int
    {
        if (! $request->boolean('issue_existing') || $addon->plans->isEmpty()) {
            return 0;
        }
        $actor = LicenseService::actor($request->user());
        $count = 0;
        License::query()->core()->whereIn('plan_id', $addon->plans()->pluck('plans.id'))->whereIn('status', [License::ACTIVE, License::PENDING])
            ->whereDoesntHave('addonLicenses', fn ($q) => $q->where('addon_id', $addon->id)->where('status', '!=', License::REVOKED))
            ->each(function (License $license) use ($licenses, $actor, &$count) {
                $licenses->syncAddons($license, $actor);
                $count++;
            });

        return $count;
    }

    public function updateCoupons(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate(['coupons' => ['nullable', 'string', 'max:2000'], 'annual_note' => ['nullable', 'string', 'max:300'], 'lifetime_note' => ['nullable', 'string', 'max:300']]);
        $settings->set(['pricing.coupons' => $data['coupons'], 'pricing.annual_note' => $data['annual_note'], 'pricing.lifetime_note' => $data['lifetime_note']]);

        return back()->with('toast', 'Coupons & notes saved');
    }
}
