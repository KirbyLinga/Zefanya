@php
    // Error pages can render without a session, so they use no auth or session helpers.
    $panel = match (request()->segment(1)) {
        'seller' => ['Seller dashboard', url('/seller/dashboard')],
        'logistics' => ['Logistics dashboard', url('/logistics')],
        'rider' => ['Rider dashboard', url('/rider/dashboard')],
        'admin' => ['Admin dashboard', url('/admin/dashboard')],
        default => null,
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('title') — Zefanya</title>
    <x-fonts />
    <style>
        :root { --ink: #4a2521; --muted: #7a6a66; --bg: #fdf3f1; --card: #ffffff; --accent: #7a3b45; --line: #ecd9d5; }
        @media (prefers-color-scheme: dark) { :root { --ink: #f6e9e6; --muted: #c9b5b0; --bg: #1f1514; --card: #2a1d1b; --accent: #d99aa4; --line: #43302d; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: var(--bg); color: var(--ink); font-family: 'Montserrat', system-ui, sans-serif; }
        main { width: 100%; max-width: 480px; text-align: center; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 40px 28px; }
        .brand { font-family: 'Playfair Display', Georgia, serif; font-size: 22px; margin: 0 0 24px; color: var(--accent); text-decoration: none; display: inline-block; }
        .code { font-family: 'Playfair Display', Georgia, serif; font-size: 64px; line-height: 1; margin: 0; }
        h1 { font-size: 20px; margin: 12px 0 8px; }
        p { color: var(--muted); font-size: 14px; line-height: 1.6; margin: 0 0 24px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 20px; border-radius: 10px; border: 1px solid var(--accent); font: 600 13px 'Montserrat', system-ui, sans-serif; text-decoration: none; cursor: pointer; background: transparent; color: var(--accent); }
        .btn-primary { background: var(--accent); color: #fff; }
        @media (prefers-color-scheme: dark) { .btn-primary { color: #1f1514; } }
        .btn:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
    </style>
</head>
<body>
    <main role="main">
        <a class="brand" href="{{ url('/') }}">Zefanya</a>
        <p class="code" aria-hidden="true">@yield('code')</p>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            <a class="btn btn-primary" href="{{ url('/') }}">Go to home</a>
            @if ($panel)<a class="btn" href="{{ $panel[1] }}">{{ $panel[0] }}</a>@endif
            <button type="button" class="btn" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/') }}')">Go back</button>
        </div>
    </main>
</body>
</html>
