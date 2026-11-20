@php $accent = setting('branding.color_primary'); @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>License certificate {{ $license->number }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: 'DejaVu Sans', Helvetica, sans-serif; color: #1d1d2c; }
        .page { padding: 48px 64px; border: 14px solid {{ $accent }}; height: 100%; box-sizing: border-box; }
        .muted { color: #6b6b80; }
        .label { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: #8a8aa0; }
        h1 { font-size: 30px; margin: 6px 0 0; letter-spacing: -0.5px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 8px 0; vertical-align: top; }
        .key { font-family: 'DejaVu Sans Mono', monospace; font-size: 15px; letter-spacing: 2px; }
    </style>
</head>
<body>
<div class="page">
    <table><tr>
        <td><div style="font-size: 18px; font-weight: bold;">{{ setting('branding.site_name') }}</div><div class="muted" style="font-size: 11px;">{{ setting('invoice.company_name') }}</div></td>
        <td style="text-align: right;"><div class="label">Certificate number</div><div style="font-weight: bold;">{{ $license->number }}</div></td>
    </tr></table>

    <div style="margin-top: 36px;">
        <div class="label">Software license certificate</div>
        <h1>{{ setting('branding.product_name', 'LabelDesk') }} {{ $license->plan->name }} · {{ ucfirst($license->billing_type) }} license</h1>
        <p class="muted" style="font-size: 12px; margin-top: 10px;">This certifies that the organisation below holds a license to install and use {{ setting('branding.product_name', 'LabelDesk') }} under its own brand, on the number of production domains shown, under the License Agreement in force on the purchase date.</p>
    </div>

    <table style="margin-top: 24px; font-size: 12px;">
        <tr>
            <td style="width: 50%;"><div class="label">Licensed to</div><div style="font-size: 16px; font-weight: bold;">{{ $license->organisation->name }}</div>
                @if ($license->organisation->country)<div class="muted">{{ $license->organisation->country }}</div>@endif
                @if ($license->organisation->vat_id)<div class="muted">VAT / GST ID: {{ $license->organisation->vat_id }}</div>@endif</td>
            <td><div class="label">License key</div><div class="key">{{ $license->maskedKey() }}</div><div class="muted" style="font-size: 10px;">The full key is shown only in the customer portal.</div></td>
        </tr>
        <tr>
            <td><div class="label">Production domains</div><div>{{ $license->domainLimit() }} ({{ $license->domains_included }} included{{ $license->domains_extra ? ' + '.$license->domains_extra.' extra' : '' }}), staging unlimited</div></td>
            <td><div class="label">Purchased</div><div>{{ $license->purchased_at->format('F j, Y') }}</div></td>
        </tr>
        <tr>
            <td><div class="label">{{ $license->expires_at ? 'Valid until (renewable)' : 'Valid' }}</div><div>{{ $license->expires_at ? $license->expires_at->format('F j, Y') : 'Perpetual' }}</div></td>
            <td><div class="label">Updates & support until</div><div>{{ $license->support_until?->format('F j, Y') ?? '—' }}</div></td>
        </tr>
        <tr>
            <td><div class="label">Status</div><div>{{ ucfirst($license->displayStatus()) }}</div></td>
            <td><div class="label">Issued</div><div>{{ now()->format('F j, Y') }} (UTC)</div></td>
        </tr>
    </table>

    <p class="muted" style="font-size: 9px; margin-top: 30px;">Verify this certificate by contacting {{ setting('contact.email_support') }} with the certificate number. {{ setting('branding.site_name') }} is a software vendor; this license covers software only.</p>
</div>
</body>
</html>
