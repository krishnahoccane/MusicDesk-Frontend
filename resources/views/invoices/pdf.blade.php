@php
    $screen = $screen ?? false;
    $fmt = fn ($v) => fmt_money((float) $v, $invoice->currency);
    $seller = $invoice->seller ?? [];
    $buyer = $invoice->buyer ?? [];
    $accent = setting('branding.color_primary');
    $tz = $screen ? user_timezone() : 'UTC';
    $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->setTimezone($tz)->format('M d, Y') : '—';
    $status = ['paid' => 'PAID', 'open' => 'DUE', 'refunded' => 'REFUNDED', 'void' => 'VOID'][$invoice->status] ?? strtoupper($invoice->status);
    $gatewayNames = ['razorpay' => 'Razorpay', 'stripe' => 'Stripe', 'paypal' => 'PayPal', 'bank_transfer' => 'Bank transfer'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; font-size: 11px; color: #1d1d2c; margin: 0; {{ $screen ? 'background:#e9e9f1;' : '' }} }
        .sheet { background: #fff; padding: 40px 44px; {{ $screen ? 'max-width: 820px; margin: 24px auto; border-radius: 14px; box-shadow: 0 20px 60px -20px rgba(0,0,0,.35);' : '' }} }
        .bar { height: 5px; background: {{ $accent }}; margin: -40px -44px 32px; {{ $screen ? 'border-radius: 14px 14px 0 0;' : '' }} }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #6b6b80; }
        .h1 { font-size: 24px; font-weight: bold; letter-spacing: -0.5px; margin: 0; }
        .brand { font-size: 16px; font-weight: bold; }
        .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: 1.2px; color: #8a8aa0; margin-bottom: 4px; }
        .status { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: 9px; font-weight: bold; letter-spacing: 1px;
                  background: {{ $invoice->status === 'paid' ? '#dcfce9' : ($invoice->status === 'open' ? '#fff4dc' : '#eceaf5') }};
                  color: {{ $invoice->status === 'paid' ? '#0d7a50' : ($invoice->status === 'open' ? '#9a6200' : '#4a4a63') }}; }
        .lines th { text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: 1px; color: #8a8aa0; border-bottom: 1px solid #e4e4ee; padding: 8px 6px; }
        .lines td { padding: 10px 6px; border-bottom: 1px solid #f0f0f5; vertical-align: top; }
        .r { text-align: right; }
        .totals td { padding: 5px 6px; }
        .grand td { font-size: 14px; font-weight: bold; border-top: 2px solid #1d1d2c; padding-top: 10px; }
        .foot { margin-top: 36px; padding-top: 14px; border-top: 1px solid #e4e4ee; font-size: 9.5px; color: #6b6b80; }
        .toolbar { max-width: 820px; margin: 24px auto 0; display: flex; justify-content: space-between; font-family: system-ui, sans-serif; font-size: 13px; }
        .toolbar a { color: #4a4a63; text-decoration: none; padding: 8px 12px; border-radius: 8px; background: #fff; }
        .toolbar a.primary { background: {{ $accent }}; color: #fff; }
        @media print { .toolbar { display: none; } body { background: #fff; } .sheet { box-shadow: none; margin: 0; } }
    </style>
</head>
<body>
@if ($screen)
    <div class="toolbar">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('dashboard.billing') }}">← Back</a>
        <span>
            <a href="#" onclick="window.print(); return false;">Print</a>
            <a class="primary" href="{{ route('dashboard.invoices.pdf', $invoice) }}">Download PDF</a>
        </span>
    </div>
@endif
<div class="sheet">
    <div class="bar"></div>
    <table>
        <tr>
            <td style="vertical-align: top;">
                <div class="brand">{{ $seller['name'] ?? setting('branding.site_name') }}</div>
                <div class="muted" style="margin-top: 6px; white-space: pre-line; line-height: 1.5;">{{ $seller['address'] ?? '' }}</div>
                @if (! empty($seller['tax_id']))<div class="muted" style="margin-top: 4px;">Tax ID: {{ $seller['tax_id'] }}</div>@endif
                @if (! empty($seller['email']))<div class="muted">{{ $seller['email'] }}</div>@endif
            </td>
            <td class="r" style="vertical-align: top;">
                <p class="h1">Invoice</p>
                <div style="margin-top: 6px; font-size: 12px;">{{ $invoice->number }}</div>
                <div style="margin-top: 8px;"><span class="status">{{ $status }}</span></div>
            </td>
        </tr>
    </table>

    <table style="margin-top: 32px;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <div class="label">Billed to</div>
                <div style="font-weight: bold; font-size: 12px;">{{ $buyer['name'] ?? '' }}</div>
                @if (! empty($buyer['address']['text']))<div class="muted" style="white-space: pre-line; line-height: 1.5;">{{ $buyer['address']['text'] }}</div>@endif
                @if (! empty($buyer['country']))<div class="muted">{{ $buyer['country'] }}</div>@endif
                @if (! empty($buyer['vat_id']))<div class="muted">VAT / GST ID: {{ $buyer['vat_id'] }}</div>@endif
                @if (! empty($buyer['email']))<div class="muted">{{ $buyer['email'] }}</div>@endif
            </td>
            <td style="width: 50%; vertical-align: top;">
                <table>
                    <tr><td class="label">Issued</td><td class="r">{{ $date($invoice->issued_at) }}</td></tr>
                    @if ($invoice->due_at)<tr><td class="label">Due</td><td class="r">{{ $date($invoice->due_at) }}</td></tr>@endif
                    @if ($invoice->paid_at)<tr><td class="label">Paid</td><td class="r">{{ $date($invoice->paid_at) }}</td></tr>@endif
                    @if ($invoice->order)<tr><td class="label">Order</td><td class="r">{{ $invoice->order->number }}</td></tr>@endif
                    @if ($invoice->payment_method)<tr><td class="label">Payment</td><td class="r">{{ $gatewayNames[$invoice->payment_method] ?? $invoice->payment_method }}</td></tr>@endif
                    <tr><td class="label">Currency</td><td class="r">{{ $invoice->currency }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 28px;">
        <thead><tr><th>Description</th><th class="r" style="width: 60px;">Qty</th><th class="r" style="width: 110px;">Unit</th><th class="r" style="width: 110px;">Amount</th></tr></thead>
        <tbody>
            @foreach ($invoice->lines as $l)
                <tr>
                    <td>{{ $l['description'] }}</td>
                    <td class="r">{{ $l['quantity'] ?? 1 }}</td>
                    <td class="r">{{ $fmt($l['unit'] ?? $l['amount']) }}</td>
                    <td class="r">{{ $fmt($l['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 12px; width: 46%; margin-left: 54%;">
        <tr><td class="muted">Subtotal</td><td class="r">{{ $fmt($invoice->subtotal) }}</td></tr>
        @if ($invoice->discount > 0)<tr><td class="muted">Discount{{ $invoice->order?->coupon ? ' ('.$invoice->order->coupon.')' : '' }}</td><td class="r">−{{ $fmt($invoice->discount) }}</td></tr>@endif
        <tr><td class="muted">Tax ({{ rtrim(rtrim(number_format($invoice->tax_rate * 100, 2), '0'), '.') }}%)</td><td class="r">{{ $fmt($invoice->tax) }}</td></tr>
        @if ($invoice->tax_rate == 0 && ! empty($buyer['vat_id']))<tr><td colspan="2" class="muted" style="font-size: 9px;">Reverse charge / export: tax to be accounted for by the recipient.</td></tr>@endif
        <tr class="grand"><td>Total</td><td class="r">{{ $fmt($invoice->total) }}</td></tr>
        @if ($invoice->currency !== 'USD' && $invoice->exchange_rate > 0)
            <tr><td colspan="2" class="muted r" style="font-size: 9px;">≈ USD {{ number_format($invoice->total / $invoice->exchange_rate, 2) }} at 1 USD = {{ rtrim(rtrim(number_format($invoice->exchange_rate, 4), '0'), '.') }} {{ $invoice->currency }}</td></tr>
        @endif
    </table>

    <div class="foot">
        {{ $seller['footer'] ?? '' }}
        <div style="margin-top: 6px;">All dates {{ $screen ? 'shown in '.$tz : 'in UTC' }}. {{ setting('branding.site_name') }} is a software vendor; this invoice covers software licenses and services.</div>
    </div>
</div>
</body>
</html>
