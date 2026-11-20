<x-layouts.app area="admin" title="Issue a license" subtitle="For manual sales (bank transfer, invoice), partners, comps and replacements. The key is shown once after creation.">
    <x-slot:eyebrow><a href="{{ route('admin.licenses') }}" class="hover:text-white">Admin · Licenses</a></x-slot:eyebrow>

    <form method="POST" action="{{ route('admin.licenses.store') }}" class="grid max-w-4xl gap-4"
          x-data="{ existing: @js(old('organisation_id', $selected) ? true : ($organisations->isNotEmpty())), sale: {{ old('record_sale') ? 'true' : 'false' }} }">
        @csrf
        <section class="card card-pad space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-white">Customer</h2>
                <div class="inline-flex rounded-xl border hairline p-1 text-xs">
                    <button type="button" @click="existing = true" :class="existing ? 'bg-white/10 text-white' : 'text-ink-400'" class="rounded-lg px-3 py-1.5" @disabled($organisations->isEmpty())>Existing</button>
                    <button type="button" @click="existing = false" :class="! existing ? 'bg-white/10 text-white' : 'text-ink-400'" class="rounded-lg px-3 py-1.5">New customer</button>
                </div>
            </div>
            <div x-show="existing">
                <label class="label" for="organisation_id">Organisation</label>
                <select id="organisation_id" name="organisation_id" class="input" :disabled="! existing">
                    @foreach ($organisations as $o)<option value="{{ $o->id }}" @selected((string) old('organisation_id', $selected) === (string) $o->id)>{{ $o->name }} · {{ $o->email }}</option>@endforeach
                </select>
            </div>
            <div x-show="! existing" x-cloak class="grid gap-4 sm:grid-cols-[1fr_1fr_120px]">
                <div><label class="label" for="new_org_name">Organisation name</label><input id="new_org_name" name="new_org_name" value="{{ old('new_org_name') }}" class="input" :disabled="existing"></div>
                <div><label class="label" for="new_org_email">Billing email</label><input id="new_org_email" name="new_org_email" type="email" value="{{ old('new_org_email') }}" class="input" :disabled="existing"></div>
                <div><label class="label" for="new_org_country">Country</label><input id="new_org_country" name="new_org_country" maxlength="2" value="{{ old('new_org_country') }}" placeholder="IN" class="input uppercase" :disabled="existing"></div>
                <p class="text-xs text-ink-500 sm:col-span-3">The customer can create a portal login later by registering with this email, or you can invite them from their customer page.</p>
            </div>
        </section>

        <section class="card card-pad grid gap-4 sm:grid-cols-2">
            <h2 class="font-semibold text-white sm:col-span-2">License</h2>
            <div><label class="label" for="plan_id">Plan</label>
                <select id="plan_id" name="plan_id" class="input">@foreach ($plans as $p)<option value="{{ $p->id }}" @selected((string) old('plan_id') === (string) $p->id)>{{ $p->name }} · {{ $p->domains }} domain(s){{ $p->active ? '' : ' (hidden)' }}</option>@endforeach</select></div>
            <div><label class="label" for="billing_type">Type</label>
                <select id="billing_type" name="billing_type" class="input"><option value="annual" @selected(old('billing_type') === 'annual')>Annual (renews yearly)</option><option value="lifetime" @selected(old('billing_type') === 'lifetime')>Lifetime (12 months of updates)</option></select></div>
            <div><label class="label" for="domains_extra">Extra production domains</label><input id="domains_extra" name="domains_extra" type="number" min="0" value="{{ old('domains_extra', 0) }}" class="input font-mono"></div>
            <div><label class="label" for="status">Start as</label>
                <select id="status" name="status" class="input"><option value="active">Active</option><option value="pending" @selected(old('status') === 'pending')>Awaiting payment (works 14 days)</option></select></div>
            <div class="sm:col-span-2"><label class="label" for="primary_domain">Primary domain <span class="text-ink-500">(optional)</span></label><input id="primary_domain" name="primary_domain" value="{{ old('primary_domain') }}" placeholder="portal.customer.com" class="input"></div>
            <div class="sm:col-span-2"><label class="label" for="notes">Internal note <span class="text-ink-500">(admins only)</span></label><textarea id="notes" name="notes" rows="2" class="input" placeholder="e.g. Partner comp approved by …">{{ old('notes') }}</textarea></div>
        </section>

        <section class="card card-pad space-y-4">
            <label class="flex items-start gap-3 text-sm">
                <input type="hidden" name="record_sale" value="0">
                <input type="checkbox" name="record_sale" value="1" x-model="sale" class="mt-0.5 size-4 accent-volt-500">
                <span><span class="block text-ink-100">Record a sale for this license</span><span class="text-xs text-ink-500">Creates an order and an invoice. Leave unticked for comps and replacements.</span></span>
            </label>
            <div x-show="sale" x-cloak class="grid gap-4 sm:grid-cols-4">
                <div class="sm:col-span-2"><label class="label" for="amount">Amount (incl. any tax)</label><input id="amount" name="amount" type="number" step="0.01" min="0" value="{{ old('amount') }}" class="input font-mono"></div>
                <div><label class="label" for="currency">Currency</label><select id="currency" name="currency" class="input">@foreach ($currencies as $c)<option @selected(old('currency', 'USD') === $c)>{{ $c }}</option>@endforeach</select></div>
                <div><label class="label" for="payment">Payment</label><select id="payment" name="payment" class="input"><option value="bank_transfer">Bank transfer</option><option value="manual">Other / manual</option></select></div>
                <label class="flex items-center gap-2 text-sm text-ink-300 sm:col-span-4"><input type="hidden" name="paid" value="0"><input type="checkbox" name="paid" value="1" class="size-4 accent-volt-500" @checked(old('paid', '1') === '1')> Already paid (otherwise the invoice is issued as due)</label>
            </div>
        </section>

        <div class="flex justify-end gap-2">
            <a href="{{ route('admin.licenses') }}" class="btn btn-ghost">Cancel</a>
            <button class="btn btn-primary"><x-icon name="key" class="size-4"/> Create license</button>
        </div>
    </form>
</x-layouts.app>
