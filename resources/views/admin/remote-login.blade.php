<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Signing in to {{ $domain }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #07070b; color: #c9c7d6; font: 15px/1.6 system-ui, sans-serif; }
        .box { max-width: 420px; padding: 32px; text-align: center; }
        button { margin-top: 16px; padding: 10px 20px; border: 0; border-radius: 10px; background: #7c5cff; color: #fff; font-weight: 600; cursor: pointer; }
        small { display: block; margin-top: 16px; color: #6b6880; }
    </style>
</head>
<body>
    <form class="box" method="POST" action="{{ $url }}" id="sso">
        <input type="hidden" name="token" value="{{ $token }}">
        <div>Signing in to <b style="color:#fff">{{ $domain }}</b> as its Super Admin…</div>
        <noscript><button>Continue</button></noscript>
        <small>If nothing happens, the site may run LabelDesk older than 4.3.4 or be offline. The sign-in link expires in 2 minutes.</small>
    </form>
    <script>document.getElementById('sso').submit();</script>
</body>
</html>
