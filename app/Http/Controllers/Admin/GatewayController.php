<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\PaymentGateway;
use App\Payments\GatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GatewayController extends Controller
{
    public function index(GatewayManager $manager): View
    {
        return view('admin.gateways', ['gateways' => $manager->all(), 'currencies' => Currency::enabled()->pluck('code')]);
    }

    /** The on/off switch on the gateway list (JSON). */
    public function toggle(Request $request, string $code, GatewayManager $manager): \Illuminate\Http\JsonResponse
    {
        $driver = $manager->driver($code);
        $model = PaymentGateway::firstOrNew(['code' => $code], ['name' => $driver->name(), 'mode' => 'test']);
        $model->enabled = $request->boolean('enabled');
        $model->save();
        $configured = $manager->driver($code)->isConfigured();

        return response()->json([
            'enabled' => (bool) $model->enabled,
            'configured' => $configured,
            'message' => $driver->name().($model->enabled ? ' is on'.($configured ? '' : ', but its keys are missing, so it won’t show at checkout yet') : ' is off'),
        ]);
    }

    public function update(Request $request, string $code, GatewayManager $manager): RedirectResponse
    {
        $driver = $manager->driver($code);
        $model = PaymentGateway::firstOrNew(['code' => $code], ['name' => $driver->name()]);

        $request->validate(['mode' => ['required', 'in:test,live'], 'currencies' => ['array']]);

        // Secret fields left blank keep their stored value.
        $creds = $model->credentials ?? [];
        foreach ($driver->credentialFields() as $key => $field) {
            $value = $request->input("credentials.$key");
            if (($field['secret'] ?? false) && blank($value)) {
                continue;
            }
            $creds[$key] = $value;
        }

        $model->fill([
            'name' => $driver->name(),
            'enabled' => $request->boolean('enabled'),
            'mode' => $request->input('mode'),
            'credentials' => $creds,
            'currencies' => array_values(array_filter((array) $request->input('currencies', []))) ?: null,
        ])->save();

        $warning = $model->enabled && ! $manager->driver($code)->isConfigured() ? ' (enabled, but required keys are missing, so it won’t show at checkout)' : '';

        return back()->with('toast', $driver->name().' saved'.$warning);
    }
}
