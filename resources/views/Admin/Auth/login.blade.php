<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Login — Zefanya</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/admin/admin.css'])
</head>
<body class="admin-login-body">

    <div class="admin-login-card">
        <img class="admin-login-card__logo" src="{{ asset('Images/Zefanya-Logo-128.png') }}" alt="Zefanya logo" />
        <h1 class="admin-login-card__title">Admin Login</h1>
        <p class="admin-login-card__subtitle">Restricted access — Zefanya staff only.</p>

        @if ($errors->any())
            <div class="admin-login-card__error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.login.post') }}">
            @csrf>

            <label class="admin-login-card__label" for="email">Email address</label>
            <input class="input" type="email" name="email" id="email" value="{{ old('email') }}" required autofocus />

            <label class="admin-login-card__label" for="password">Password</label>
            <input class="input" type="password" name="password" id="password" required />

            <label class="admin-login-card__remember">
                <input type="checkbox" name="remember" />
                <span>Remember me</span>
            </label>

            <button class="btn btn--inverted" type="submit">LOG IN</button>
        </form>
    </div>

</body>
</html>