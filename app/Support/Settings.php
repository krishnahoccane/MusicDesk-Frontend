<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Key/value site settings with defaults from config/site.php.
 * Keys are "group.field", e.g. setting('branding.color_primary').
 */
class Settings
{
    private const CACHE_KEY = 'site.settings';

    private ?array $values = null;

    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $stored = [];
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Schema::hasTable('settings')
                ? Setting::query()->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all()
                : []);
        } catch (\Throwable) {
            // DB not ready (fresh install): defaults only.
        }

        return $this->values = array_merge($this->defaults(), $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        return ($value === null || $value === '') ? $default ?? $this->defaults()[$key] ?? null : $value;
    }

    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        }
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->values = null;
    }

    public function defaults(): array
    {
        $out = [];
        foreach (config('site.groups') as $group => $def) {
            foreach ($def['fields'] as $field => $meta) {
                $out["$group.$field"] = $meta['default'] ?? null;
            }
        }

        return $out;
    }

    /** Public URL for an uploaded image setting, or null. */
    public function image(string $key): ?string
    {
        $path = $this->get($key);

        return $path ? Storage::disk('public')->url($path) : null;
    }

    /** CSS variables injected into every page so admins can re-theme without a rebuild. */
    public function themeCss(): string
    {
        $p = $this->get('branding.color_primary');
        $s = $this->get('branding.color_secondary');
        $a = $this->get('branding.color_accent');
        $ok = $this->get('branding.color_success');
        $bg = $this->get('branding.color_background');
        $font = fn ($k) => "'".str_replace("'", '', $this->get($k))."'";

        return ":root{"
            ."--color-volt-300:color-mix(in oklab,$p 55%,white);--color-volt-400:color-mix(in oklab,$p 80%,white);"
            ."--color-volt-500:$p;--color-volt-600:color-mix(in oklab,$p 82%,black);"
            ."--color-flare-400:color-mix(in oklab,$s 80%,white);--color-flare-500:$s;"
            ."--color-amber-glow:$a;--color-mint-400:color-mix(in oklab,$ok 80%,white);--color-mint-500:$ok;"
            ."--color-ink-950:$bg;"
            ."--font-sans:{$font('branding.font_sans')},ui-sans-serif,system-ui,sans-serif;"
            ."--font-serif:{$font('branding.font_serif')},ui-serif,Georgia,serif;"
            ."--font-mono:{$font('branding.font_mono')},ui-monospace,monospace;}";
    }

    /** Bunny Fonts stylesheet URL for the selected fonts. */
    public function fontsUrl(): string
    {
        $families = collect(['branding.font_sans' => '400,500,600,700', 'branding.font_serif' => '400,400i', 'branding.font_mono' => '400,500'])
            ->map(fn ($w, $k) => str_replace(' ', '-', strtolower($this->get($k))).':'.$w)
            ->unique()->implode('|');

        return 'https://fonts.bunny.net/css?family='.$families.'&display=swap';
    }

    /** Configured social links: [label => url]. */
    public function socials(): array
    {
        return collect(['x' => 'X', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'facebook' => 'Facebook', 'github' => 'GitHub', 'discord' => 'Discord'])
            ->mapWithKeys(fn ($label, $k) => [$label => $this->get("contact.social_$k")])
            ->filter()->all();
    }
}
