<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Entrar · {{ config('app.name', 'Infocell Gestão') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="antialiased">
    <main class="flex min-h-screen items-center justify-center bg-slate-50 px-5 py-8">
        <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <img src="{{ asset('images/ainfocell-logo.png') }}" alt="AInfoCell" class="mb-6 h-auto w-full max-w-64">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Entrar no Infocell</h1>
            <p class="mt-2 text-sm text-slate-500">Acesse com sua conta corporativa.</p>
            @if ($errors->has('keycloak'))
                <p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('keycloak') }}</p>
            @endif
            <a href="{{ route('keycloak.redirect') }}" class="mt-6 block rounded-lg bg-indigo-600 px-4 py-3 text-center text-sm font-semibold text-white transition-colors hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Entrar com Keycloak</a>
        </section>
    </main>
</body>
</html>
