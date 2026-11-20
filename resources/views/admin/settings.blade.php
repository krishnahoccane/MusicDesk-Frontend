{{-- Generic settings editor generated from config/site.php --}}
<x-layouts.app area="admin" :title="$def['label']" :subtitle="$def['description'] ?? null">
    <x-slot:eyebrow>Admin · Settings</x-slot:eyebrow>
    <x-slot:actions>
        <form method="POST" action="{{ route('admin.settings.reset', $group) }}" onsubmit="return confirm('Reset {{ $def['label'] }} to the defaults?')">
            @csrf<button class="btn btn-ghost btn-sm"><x-icon name="refresh" class="size-3.5"/> Reset to defaults</button>
        </form>
    </x-slot:actions>

    <form method="POST" action="{{ route('admin.settings.update', $group) }}" enctype="multipart/form-data"
          class="grid gap-6 {{ $group === 'branding' ? 'xl:grid-cols-[1fr_380px]' : 'max-w-3xl' }}"
          x-data="{
            c: { primary: @js($settings->get('branding.color_primary')), secondary: @js($settings->get('branding.color_secondary')), accent: @js($settings->get('branding.color_accent')), bg: @js($settings->get('branding.color_background')) },
            name: @js($settings->get('branding.site_name')), font: @js($settings->get('branding.font_sans')), serif: @js($settings->get('branding.font_serif')),
          }">
        @csrf
        <div class="card card-pad space-y-5">
            @foreach ($def['fields'] as $field => $meta)
                @php $value = old($field, $settings->get("$group.$field")); $id = "f_$field"; @endphp
                <div>
                    @if ($meta['type'] !== 'toggle')
                        <label class="label" for="{{ $id }}">{{ $meta['label'] }}</label>
                    @endif

                    @switch($meta['type'])
                        @case('textarea')
                            <textarea id="{{ $id }}" name="{{ $field }}" rows="{{ str_contains($field, 'address') || str_contains($field, 'rules') ? 4 : 3 }}" class="input">{{ $value }}</textarea>
                            @break
                        @case('color')
                            @php $model = ['color_primary' => 'c.primary', 'color_secondary' => 'c.secondary', 'color_accent' => 'c.accent', 'color_background' => 'c.bg'][$field] ?? null; @endphp
                            <div class="flex items-center gap-3">
                                <label class="relative size-11 shrink-0 cursor-pointer overflow-hidden rounded-xl ring-1 ring-white/15" @if ($model) :style="`background: ${ {{ $model }} }`" @else style="background: {{ $value }}" @endif>
                                    <input type="color" class="absolute inset-0 cursor-pointer opacity-0" value="{{ $value }}" @if ($model) x-model="{{ $model }}" @endif oninput="this.closest('div').querySelector('input[type=text]').value = this.value">
                                </label>
                                <input id="{{ $id }}" type="text" name="{{ $field }}" value="{{ $value }}" class="input font-mono uppercase" maxlength="7" pattern="#[0-9a-fA-F]{6}" @if ($model) x-model="{{ $model }}" @endif>
                            </div>
                            @break
                        @case('font')
                            <select id="{{ $id }}" name="{{ $field }}" class="input" @if ($field === 'font_sans') x-model="font" @elseif ($field === 'font_serif') x-model="serif" @endif>
                                @foreach (config('site.fonts') as $f)<option @selected($value === $f)>{{ $f }}</option>@endforeach
                            </select>
                            @break
                        @case('image')
                            @php $url = $settings->image("$group.$field"); @endphp
                            <div class="flex items-center gap-4">
                                <span class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-xl border hairline bg-white/[0.03]">
                                    @if ($url)<img src="{{ $url }}" alt="" class="size-full object-contain">@else<x-icon name="upload" class="size-5 text-ink-500"/>@endif
                                </span>
                                <div class="flex-1 space-y-2">
                                    <input id="{{ $id }}" type="file" name="{{ $field }}" accept="image/png,image/jpeg,image/svg+xml,image/webp,image/x-icon"
                                           class="block w-full text-sm text-ink-300 file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-sm file:text-white hover:file:bg-white/15">
                                    @if ($url)<label class="flex items-center gap-2 text-xs text-ink-400"><input type="checkbox" name="remove_{{ $field }}" value="1" class="accent-coral-500"> Remove current image</label>@endif
                                </div>
                            </div>
                            @break
                        @case('toggle')
                            <label class="flex items-center justify-between gap-6 rounded-xl border hairline p-3.5" x-data="{ on: {{ $value ? 'true' : 'false' }} }">
                                <span><span class="block text-sm text-ink-100">{{ $meta['label'] }}</span>@isset($meta['help'])<span class="text-xs text-ink-500">{{ $meta['help'] }}</span>@endisset</span>
                                <input type="hidden" name="{{ $field }}" :value="on ? 1 : 0">
                                <button type="button" class="switch" :aria-checked="on.toString()" @click="on = !on" role="switch"><span></span></button>
                            </label>
                            @break
                        @case('select')
                            <select id="{{ $id }}" name="{{ $field }}" class="input">
                                @foreach ($meta['options'] as $ov => $ol)<option value="{{ $ov }}" @selected($value === $ov)>{{ $ol }}</option>@endforeach
                            </select>
                            @break
                        @default
                            <input id="{{ $id }}" type="{{ in_array($meta['type'], ['email', 'url', 'number']) ? $meta['type'] : 'text' }}" name="{{ $field }}" value="{{ $value }}" class="input" @if ($meta['type'] === 'number') step="any" @endif
                                   @if ($field === 'site_name') x-model="name" @endif>
                    @endswitch

                    @if (isset($meta['help']) && $meta['type'] !== 'toggle')<p class="mt-1.5 text-xs text-ink-500">{{ $meta['help'] }}</p>@endif
                    @error($field)<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <div class="flex justify-end border-t hairline pt-5"><button class="btn btn-primary">Save {{ strtolower($def['label']) }}</button></div>
        </div>

        @if ($group === 'branding')
            {{-- Live preview --}}
            <aside class="xl:sticky xl:top-24 xl:self-start">
                <div class="eyebrow mb-3">Live preview</div>
                <div class="overflow-hidden rounded-2xl border border-white/10 p-6" :style="`background: ${c.bg}`">
                    <div class="flex items-center gap-2.5">
                        <span class="size-8 rounded-[10px]" :style="`background: conic-gradient(from 210deg, ${c.primary}, ${c.secondary}, ${c.accent}, ${c.primary})`"></span>
                        <span class="font-semibold text-white" :style="`font-family: '${font}'`" x-text="name"></span>
                    </div>
                    <div class="mt-8 text-3xl font-semibold leading-tight tracking-tight text-white" :style="`font-family: '${font}'`">
                        Your music platform.<br>
                        <span class="italic" :style="`font-family: '${serif}'; background: linear-gradient(100deg, #fff, ${c.primary}, ${c.secondary}, ${c.accent}); -webkit-background-clip: text; background-clip: text; color: transparent`">Your brand.</span>
                    </div>
                    <div class="mt-6 flex gap-2">
                        <span class="rounded-xl px-4 py-2 text-sm font-medium text-white" :style="`background: ${c.primary}; font-family: '${font}'`">Buy a license</span>
                        <span class="rounded-xl border border-white/15 px-4 py-2 text-sm text-white" :style="`font-family: '${font}'`">Learn more</span>
                    </div>
                    <div class="mt-6 flex gap-2">
                        <span class="rounded-full px-2.5 py-0.5 text-[11px]" :style="`background: ${c.primary}22; color: ${c.primary}`">Active</span>
                        <span class="rounded-full px-2.5 py-0.5 text-[11px]" :style="`background: ${c.secondary}22; color: ${c.secondary}`">New</span>
                        <span class="rounded-full px-2.5 py-0.5 text-[11px]" :style="`background: ${c.accent}22; color: ${c.accent}`">Expiring</span>
                    </div>
                </div>
                <p class="mt-3 text-xs text-ink-500">Fonts preview once saved (they load from Bunny Fonts). Colors apply to the whole site and the customer portal.</p>
            </aside>
        @endif
    </form>
</x-layouts.app>
