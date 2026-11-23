<!doctype html>
<html><body style="margin:0;background:#f4f4f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d1b2b">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 12px"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;padding:32px">
<tr><td>
    <p style="margin:0 0 4px;font-size:13px;color:#6b6880">{{ $site }} · {{ $product }} license {{ $license->number }}</p>
    <h1 style="margin:0 0 16px;font-size:20px">Your {{ $product }} deactivation code</h1>
    <p style="font-size:15px;line-height:1.6">Someone with administrator access to <b>{{ $domain }}</b> asked to deactivate {{ $product }} on that domain (license {{ $license->number }}).</p>
    <p style="margin:24px 0;text-align:center"><span style="display:inline-block;background:#f4f4f7;border-radius:12px;padding:14px 22px;font-family:SFMono-Regular,Consolas,monospace;font-size:28px;font-weight:700;letter-spacing:8px">{{ $code }}</span></p>
    <p style="font-size:15px;line-height:1.6">It expires in {{ $minutes }} minutes.</p>
    <p style="font-size:13px;line-height:1.6;color:#6b6880">If this wasn’t you, ignore this email: nothing changes without the code. You can also see and manage your domains at <a href="{{ $portalUrl }}" style="color:#7c5cff">{{ $portalUrl }}</a>.</p>
</td></tr>
</table>
</td></tr></table>
</body></html>
