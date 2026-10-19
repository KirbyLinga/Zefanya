{{--
    Standalone (no Vite dependency) so it renders even before `npm run build`.
    The reset link emailed to the user points here.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Reset password · Zefanya</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #faf7f5; font-family: 'Montserrat', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            color: #1c1917; padding: 24px;
        }
        .card {
            width: 100%; max-width: 420px; background: #fff; border: 1px solid #e7e5e4;
            border-radius: 14px; padding: 32px; box-shadow: 0 10px 30px rgba(28, 25, 23, .06);
        }
        h1 { font-family: Georgia, 'Playfair Display', serif; font-size: 26px; font-weight: 400; margin: 0 0 6px; }
        p.lead { margin: 0 0 22px; color: #57534e; font-size: 14px; line-height: 1.5; }
        label { display: block; font-size: 12px; font-weight: 600; letter-spacing: .3px; color: #57534e; margin-bottom: 6px; }
        input[type=email], input[type=password] {
            width: 100%; padding: 11px 12px; border: 1px solid #d6d3d1; border-radius: 9px; font-size: 14px;
        }
        input:focus { outline: 2px solid #b45309; outline-offset: 1px; border-color: #b45309; }
        .field { margin-bottom: 16px; }
        .btn {
            display: inline-block; width: 100%; text-align: center; padding: 12px 16px; margin-top: 6px;
            border: 0; border-radius: 9px; background: #1c1917; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer;
        }
        .btn:hover { background: #292524; }
        .msg { padding: 11px 13px; border-radius: 9px; font-size: 13px; margin-bottom: 18px; line-height: 1.45; }
        .msg--err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .hint { font-size: 12px; color: #a8a29e; margin-top: 6px; }
        .back { display: block; text-align: center; margin-top: 18px; font-size: 13px; color: #78716c; text-decoration: none; }
        .back:hover { color: #1c1917; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Reset password</h1>
        <p class="lead">Choose a new password for your Zefanya account.</p>

        @if ($errors->any())
            <div class="msg msg--err">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="field">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email">
            </div>

            <div class="field">
                <label for="password">New password</label>
                <input id="password" type="password" name="password" required autofocus autocomplete="new-password">
                <div class="hint">At least 8 characters.</div>
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn">Reset password</button>
        </form>

        <a class="back" href="{{ route('home', ['login' => 1]) }}">Back to sign in</a>
    </div>
</body>
</html>
