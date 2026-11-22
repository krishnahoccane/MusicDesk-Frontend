<!doctype html>
<html><body style="margin:0;background:#f4f4f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d1b2b">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 12px"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;padding:32px">
<tr><td>
    <p style="margin:0 0 4px;font-size:13px;color:#6b6880">{{ $site }} · Security alert</p>
    <h1 style="margin:0 0 16px;font-size:20px">License check failed on {{ $alert->domain }}</h1>
    <table cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;line-height:1.7">
        <tr><td style="color:#6b6880;width:140px">Reason</td><td><b>{{ $reason }}</b> <span style="color:#6b6880">({{ $alert->last_reason }})</span></td></tr>
        <tr><td style="color:#6b6880">Domain</td><td>{{ $alert->domain }} · {{ $known ? 'licensed to '.$known->license->organisation->name.' ('.$known->license->number.')' : 'not on any license' }}</td></tr>
        @if ($alert->license && ! $known)<tr><td style="color:#6b6880">Key used</td><td>{{ $alert->license->number }} ({{ $alert->license->organisation->name }})</td></tr>@endif
        <tr><td style="color:#6b6880">Attempts</td><td>{{ $alert->attempts }} since {{ $alert->first_seen_at->utc()->format('M j, Y H:i') }} UTC</td></tr>
        @if ($alert->ip)<tr><td style="color:#6b6880">IP</td><td>{{ $alert->ip }}</td></tr>@endif
        @if ($alert->version)<tr><td style="color:#6b6880">Version</td><td>{{ $alert->version }}{{ $alert->php_version ? ' · PHP '.$alert->php_version : '' }}</td></tr>@endif
        @if ($alert->message)<tr><td style="color:#6b6880">Message</td><td>{{ $alert->message }}</td></tr>@endif
        <tr><td style="color:#6b6880">Reported by</td><td>{{ $alert->source === 'server' ? 'this license server (activation refused)' : 'the LabelDesk install' }}</td></tr>
    </table>
    <p style="margin:24px 0"><a href="{{ $statusUrl }}" style="display:inline-block;background:#7c5cff;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Review: issue a license or block</a></p>
    <p style="font-size:13px;line-height:1.6;color:#6b6880">You get one email per domain a day while it keeps failing. Blocked domains are counted but don’t email you.</p>
</td></tr>
</table>
</td></tr></table>
</body></html>
