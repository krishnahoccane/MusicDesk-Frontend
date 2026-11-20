<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/** Generic editor for every group in config/site.php (branding, homepage text, contact, invoicing, localisation). */
class SettingsController extends Controller
{
    public function edit(string $group, Settings $settings): View
    {
        $def = config("site.groups.$group") ?? abort(404);

        return view('admin.settings', ['group' => $group, 'def' => $def, 'settings' => $settings]);
    }

    public function update(Request $request, string $group, Settings $settings): RedirectResponse
    {
        $def = config("site.groups.$group") ?? abort(404);
        $values = [];

        foreach ($def['fields'] as $field => $meta) {
            $key = "$group.$field";
            switch ($meta['type']) {
                case 'image':
                    if ($request->boolean("remove_$field")) {
                        $values[$key] = null;
                    } elseif ($file = $request->file($field)) {
                        $request->validate([$field => ['image', 'mimes:png,jpg,jpeg,svg,webp,ico', 'max:2048']]);
                        $values[$key] = $file->store('site', 'public');
                    }
                    break;
                case 'toggle':
                    $values[$key] = $request->boolean($field);
                    break;
                case 'color':
                    $request->validate([$field => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
                    $values[$key] = strtolower($request->input($field));
                    break;
                case 'font':
                    $request->validate([$field => ['required', 'in:'.implode(',', config('site.fonts'))]]);
                    $values[$key] = $request->input($field);
                    break;
                case 'url':
                    $request->validate([$field => ['nullable', 'url', 'max:300']]);
                    $values[$key] = $request->input($field);
                    break;
                case 'email':
                    $request->validate([$field => ['nullable', 'email']]);
                    $values[$key] = $request->input($field);
                    break;
                case 'number':
                    $request->validate([$field => ['nullable', 'numeric']]);
                    $values[$key] = $request->input($field) === null ? null : (float) $request->input($field);
                    break;
                case 'select':
                    $request->validate([$field => ['required', 'in:'.implode(',', array_keys($meta['options']))]]);
                    $values[$key] = $request->input($field);
                    break;
                default:
                    $request->validate([$field => ['nullable', 'string', 'max:5000']]);
                    $values[$key] = $request->input($field);
            }
        }

        $settings->set($values);

        return back()->with('toast', $def['label'].' saved');
    }

    public function reset(string $group, Settings $settings): RedirectResponse
    {
        $def = config("site.groups.$group") ?? abort(404);
        $settings->set(collect($def['fields'])->mapWithKeys(fn ($m, $f) => ["$group.$f" => $m['default'] ?? null])->all());

        return back()->with('toast', $def['label'].' reset to defaults');
    }
}
