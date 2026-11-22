<!doctype html>
<html><body style="margin:0;background:#f4f4f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d1b2b">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 12px"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;padding:32px">
<tr><td>
    <p style="margin:0 0 4px;font-size:13px;color:#6b6880">{{ $site }} · {{ $product }} support · {{ $ticket->number }}</p>
    <h1 style="margin:0 0 16px;font-size:20px">{{ $ticket->subject }}</h1>
    @if ($toStaff)
        <p style="font-size:14px;line-height:1.6;color:#6b6880">{{ $isNew ? 'New ticket' : 'Reply' }} from <b style="color:#1d1b2b">{{ $message->author_name }}</b> at {{ $ticket->organisation->name }}
            · {{ $ticket->categoryLabel() }} · priority {{ $ticket->priority }}{{ $ticket->license ? ' · '.$ticket->license->number : '' }}{{ $ticket->domain ? ' · '.$ticket->domain : '' }}</p>
    @else
        <p style="font-size:14px;line-height:1.6;color:#6b6880">{{ $message->author_name }} from the {{ $product }} support team replied:</p>
    @endif
    <div style="margin:16px 0;padding:16px;border-radius:10px;background:#f4f4f7;font-size:15px;line-height:1.6;white-space:pre-wrap">{{ $message->body }}</div>
    @if ($message->attachments)<p style="font-size:13px;color:#6b6880">{{ count($message->attachments) }} attachment(s). Open the ticket to download.</p>@endif
    <p style="margin:24px 0"><a href="{{ $url }}" style="display:inline-block;background:#7c5cff;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">{{ $toStaff ? 'Open ticket' : 'View and reply' }}</a></p>
    @unless ($toStaff)<p style="font-size:13px;line-height:1.6;color:#6b6880">Reply from your portal so the whole conversation stays in one place.</p>@endunless
</td></tr>
</table>
</td></tr></table>
</body></html>
