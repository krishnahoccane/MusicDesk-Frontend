<x-layouts.marketing title="Pricing">
    <section class="mx-auto max-w-7xl px-4 pt-16 sm:px-6">
        <div class="mx-auto max-w-3xl text-center">
            <div class="eyebrow">LabelDesk licensing</div>
            <h1 class="display mt-5 text-5xl leading-[1.02] sm:text-7xl">Buy the software once. <span class="serif-accent text-gradient">Brand it forever.</span></h1>
            <p class="mx-auto mt-6 max-w-xl text-lg text-ink-300">One license per company, priced by how many branded domains you run. No per-artist fees and no revenue share.</p>
        </div>
        <div class="mt-12">@include('marketing.partials.license-tiers', ['plans' => $plans])</div>
    </section>

    {{-- Add-ons --}}
    @if ($addons->isNotEmpty())
    <section class="mx-auto mt-24 max-w-7xl px-4 sm:px-6">
        <h2 class="reveal display text-center text-3xl">Add-ons & services</h2>
        <p class="reveal mt-2 text-center text-sm text-ink-400">Add these at checkout or later from your customer portal.</p>
        <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($addons as $a)
                <div class="reveal card card-pad">
                    <span class="grid size-10 place-items-center rounded-xl bg-white/[0.04] text-volt-300 ring-1 ring-white/[0.06]"><x-icon :name="$a->icon" class="size-5"/></span>
                    <h3 class="mt-5 font-semibold text-white">{{ $a->name }}</h3>
                    <div class="mt-1">
                        @if ($a->price_from)<span class="text-xs text-ink-400">From</span>@endif
                        <span class="text-xl font-semibold text-white">{{ money($a->price)['display'] }}</span>
                        <span class="text-xs text-ink-400">{{ $a->interval ? '/ '.$a->interval : 'one-time' }}</span>
                    </div>
                    <p class="mt-2 text-sm leading-relaxed text-ink-400">{{ $a->description }}</p>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    {{-- Comparison --}}
    <section class="mx-auto mt-24 max-w-5xl px-4 sm:px-6">
        <h2 class="reveal display text-center text-3xl">Compare licenses</h2>
        <div class="reveal card mt-10 overflow-x-auto">
            <table class="table min-w-[640px]">
                <thead><tr><th class="!py-5">Feature</th><th>Starter</th><th class="!text-volt-300">Business</th><th>Enterprise</th></tr></thead>
                <tbody>
                    @foreach ([
                        ['Production domains', '1', '3', '10+'],
                        ['Staging / dev domains', '1', 'Unlimited', 'Unlimited'],
                        ['Full white-label (no MusicDesk branding)', true, true, true],
                        ['Artists, releases & users', 'Unlimited', 'Unlimited', 'Unlimited'],
                        ['Multi-currency accounting', false, true, true],
                        ['DDEX delivery module', false, true, true],
                        ['REST API & webhooks', false, true, true],
                        ['SSO / SAML & audit log', false, false, true],
                        ['Source-available', false, false, true],
                        ['Support', 'Email, 2 business days', 'Priority, 1 business day', 'Dedicated engineer + SLA'],
                    ] as $row)
                        <tr>
                            <td class="text-ink-200">{{ $row[0] }}</td>
                            @foreach (array_slice($row, 1) as $i => $cell)
                                <td class="{{ $i === 1 ? 'bg-volt-500/[0.04]' : '' }}">
                                    @if ($cell === true) <x-icon name="check" class="size-4 text-mint-400" stroke="2.5"/>
                                    @elseif ($cell === false) <span class="text-ink-600">—</span>
                                    @else <span class="text-ink-100">{{ $cell }}</span> @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="mx-auto mt-32 max-w-3xl px-4 sm:px-6">
        <h2 class="reveal display text-center text-3xl sm:text-4xl">Licensing questions</h2>
        @include('marketing.partials.faq', ['faqs' => [
            ['Is MusicDesk a music distributor?', 'No. MusicDesk is a software company. We license LabelDesk to labels and distributors, who run it under their own brand with their own store agreements. We never handle your catalog, royalties or artists.'],
            ['What counts as a domain?', 'Each production install your artists use, such as portal.yourlabel.com, uses one domain activation. Staging and local development domains are free. You can move a domain to a new one from your customer portal at any time.'],
            ['What happens if I don’t renew an annual license?', 'LabelDesk keeps working on the last version you installed. You lose access to new updates and support until you renew.'],
            ['Can I resell LabelDesk to my own clients?', 'Yes, on Enterprise or through our partner program. Each client platform uses one domain activation on your license.'],
            ['Where is it hosted?', 'On your own infrastructure: any VPS or cloud server that runs PHP 8.2+ and MySQL 8 or PostgreSQL 15. The license only checks in with our license server once a day.'],
            ['Do you offer refunds?', 'Yes. Every license comes with a 30-day money-back guarantee, no questions asked.'],
        ]])
    </section>
</x-layouts.marketing>
