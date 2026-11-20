<x-layouts.marketing title="Contact">
    <section class="mx-auto max-w-5xl px-4 pt-16 sm:px-6">
        <div class="max-w-2xl">
            <div class="eyebrow">Contact</div>
            <h1 class="display mt-5 text-5xl sm:text-6xl">Let’s talk about <span class="serif-accent text-gradient">your platform.</span></h1>
            <p class="mt-5 text-lg text-ink-300">Questions about licensing, Enterprise pricing, reseller partnerships or a live demo? We reply within one business day.</p>
        </div>
        <div class="mt-12 grid gap-4 md:grid-cols-3">
            @foreach (array_filter([
                ['mail', 'Sales', setting('contact.email_sales'), 'mailto:'.setting('contact.email_sales')],
                ['headphones', 'Support', setting('contact.email_support'), 'mailto:'.setting('contact.email_support')],
                setting('contact.phone') ? ['zap', 'Phone', setting('contact.phone'), 'tel:'.preg_replace('/[^\d+]/', '', setting('contact.phone'))] : null,
                setting('contact.whatsapp') ? ['link', 'WhatsApp', setting('contact.whatsapp'), 'https://wa.me/'.preg_replace('/\D/', '', setting('contact.whatsapp'))] : null,
            ]) as [$ic, $label, $value, $href])
                <a href="{{ $href }}" class="card card-pad group transition hover:border-white/10">
                    <span class="grid size-10 place-items-center rounded-xl bg-white/[0.04] text-volt-300 ring-1 ring-white/[0.06]"><x-icon :name="$ic" class="size-5"/></span>
                    <div class="mt-5 text-sm text-ink-400">{{ $label }}</div>
                    <div class="mt-0.5 font-medium text-white group-hover:text-volt-300">{{ $value }}</div>
                </a>
            @endforeach
            <div class="card card-pad">
                <span class="grid size-10 place-items-center rounded-xl bg-white/[0.04] text-volt-300 ring-1 ring-white/[0.06]"><x-icon name="globe" class="size-5"/></span>
                <div class="mt-5 text-sm text-ink-400">Office</div>
                <div class="mt-0.5 whitespace-pre-line text-sm text-white">{{ setting('contact.address') }}</div>
                <div class="mt-3 text-xs text-ink-500">{{ setting('contact.hours') }}</div>
            </div>
        </div>
        @if ($socials = app(\App\Support\Settings::class)->socials())
            <div class="mt-8 flex flex-wrap gap-2">
                @foreach ($socials as $label => $url)
                    <a href="{{ $url }}" target="_blank" rel="noopener" class="btn btn-outline btn-sm">{{ $label }} <x-icon name="arrow-up-right" class="size-3"/></a>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.marketing>
