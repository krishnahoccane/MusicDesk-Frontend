<!doctype html>
<html><body style="margin:0;background:#f4f4f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d1b2b">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 12px"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;padding:32px">
<tr><td>
    <p style="margin:0 0 4px;font-size:13px;color:#6b6880">{{ $site }} · Administration</p>
    <h1 style="margin:0 0 16px;font-size:20px">Join the {{ $site }} admin team</h1>
    <p style="font-size:15px;line-height:1.6">Hi {{ $member->name }},</p>
    <p style="font-size:15px;line-height:1.6">{{ $member->inviter?->name ?? 'An Owner' }} invited you to the {{ $site }} site admin as <b>{{ $member->adminRole?->name }}</b>. Set your password to get started.</p>
    <p style="margin:24px 0"><a href="{{ $link }}" style="display:inline-block;background:#7c5cff;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Accept invitation</a></p>
    <p style="font-size:13px;line-height:1.6;color:#6b6880">The link works once and expires in {{ $days }} days. If you weren’t expecting this, you can ignore this email.</p>
</td></tr>
</table>
</td></tr></table>
</body></html>
