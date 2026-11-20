<!doctype html>
<html><body style="margin:0;background:#f4f4f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d1b2b">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 12px"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;padding:32px">
<tr><td>
    <p style="margin:0 0 4px;font-size:13px;color:#6b6880">{{ $site }}</p>
    <h1 style="margin:0 0 16px;font-size:20px">Your {{ $product }} license renews in {{ $days }} {{ $days === 1 ? 'day' : 'days' }}</h1>
    <p style="font-size:15px;line-height:1.6">Hi {{ $license->organisation->name }},</p>
    <p style="font-size:15px;line-height:1.6">License <b>{{ $license->number }}</b> ({{ $license->plan->name }}, annual) expires on <b>{{ $license->expires_at->format('F j, Y') }}</b> (UTC). Renew before then to keep receiving updates and support.</p>
    <p style="margin:24px 0"><a href="{{ $renewUrl }}" style="display:inline-block;background:#7c5cff;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Renew for 12 months</a></p>
    <p style="font-size:13px;line-height:1.6;color:#6b6880">If it lapses, your sites keep running on the version you have, but new versions and support pause until you renew. You can turn these reminders off under Licenses in your portal.</p>
</td></tr>
</table>
</td></tr></table>
</body></html>
