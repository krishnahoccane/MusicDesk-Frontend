<!doctype html>
<html><body style="margin:0;background:#f4f4f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d1b2b">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 12px"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;padding:32px">
<tr><td>
    <p style="margin:0 0 4px;font-size:13px;color:#6b6880">{{ $site }}</p>
    @if ($stage === 'ending')
        <h1 style="margin:0 0 16px;font-size:20px">Your {{ $product }} free trial ends in {{ $license->trialDaysLeft() }} {{ $license->trialDaysLeft() === 1 ? 'day' : 'days' }}</h1>
        <p style="font-size:15px;line-height:1.6">Hi {{ $license->organisation->name }},</p>
        <p style="font-size:15px;line-height:1.6">Your trial of {{ $product }} {{ $license->plan?->name }} (<b>{{ $license->number }}</b>) ends on <b>{{ $license->expires_at->format('F j, Y') }}</b> (UTC). Buy the plan before then and nothing changes on your site: the same key, domains and data carry over, and the trial limits are lifted.</p>
    @elseif ($stage === 'ended')
        <h1 style="margin:0 0 16px;font-size:20px">Your {{ $product }} free trial has ended</h1>
        <p style="font-size:15px;line-height:1.6">Hi {{ $license->organisation->name }},</p>
        <p style="font-size:15px;line-height:1.6">Trial <b>{{ $license->number }}</b> ended on {{ $license->expires_at->format('F j, Y') }}. {{ $product }} is now <b>read-only</b>: you can still sign in and see everything, but not make changes. On <b>{{ $lockDate?->format('F j, Y') }}</b> it locks and the trial license is suspended.</p>
        <p style="font-size:15px;line-height:1.6">Buy the plan to carry on where you left off. Your key, domains and data are kept.</p>
    @else
        <h1 style="margin:0 0 16px;font-size:20px">Your {{ $product }} trial license is suspended</h1>
        <p style="font-size:15px;line-height:1.6">Hi {{ $license->organisation->name }},</p>
        <p style="font-size:15px;line-height:1.6">The read-only period after your free trial is over, so trial license <b>{{ $license->number }}</b> is now suspended and {{ $product }} is locked on your domains. Your data stays on your own server.</p>
        <p style="font-size:15px;line-height:1.6">Buy the plan any time: the trial license becomes your paid license and {{ $product }} unlocks at its next check-in.</p>
    @endif
    <p style="margin:24px 0"><a href="{{ $buyUrl }}" style="display:inline-block;background:#7c5cff;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Buy {{ $product }} {{ $license->plan?->name }}</a></p>
</td></tr>
</table>
</td></tr></table>
</body></html>
