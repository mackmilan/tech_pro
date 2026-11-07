<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/auth/redirect', fn () => \Laravel\Socialite\Facades\Socialite::driver('keycloak')->redirect())->name('keycloak.redirect');
Route::get('/login', fn () => redirect()->route('keycloak.redirect'))->name('login');
Route::view('/login/error', 'auth.login')->name('login.error');
Route::get('/auth/callback', function () {
    if (request()->filled('error')) {
        return redirect()->route('login.error')->withErrors([
            'keycloak' => request()->string('error_description')->toString() ?: 'A autenticação no Keycloak não foi concluída.',
        ]);
    }

    try {
        $keycloakUser = \Laravel\Socialite\Facades\Socialite::driver('keycloak')->user();
    } catch (\Laravel\Socialite\Two\InvalidStateException $exception) {
        report($exception);
        return redirect()->route('login.error')->withErrors(['keycloak' => 'A sessão de autenticação expirou ou não corresponde à solicitação. Tente entrar novamente.']);
    } catch (\GuzzleHttp\Exception\ClientException $exception) {
        report($exception);
        return redirect()->route('login.error')->withErrors(['keycloak' => 'O Keycloak recusou a troca do código de autenticação. Verifique o client ID, o client secret e a URI de callback configurada.']);
    }

    $keycloakId = $keycloakUser->getId();
    if (! $keycloakId) abort(403, 'A conta Keycloak não forneceu um identificador estável.');

    $email = $keycloakUser->getEmail();
    $user = \App\Models\User::updateOrCreate(
        ['keycloak_id' => $keycloakId],
        [
            'name' => $keycloakUser->getName() ?: $keycloakUser->getNickname() ?: $email ?: $keycloakId,
            'email' => $email ?: null,
            'password' => Str::random(64),
        ],
    );
    Auth::login($user);
    request()->session()->regenerate();
    return redirect()->intended('/');
})->name('keycloak.callback');
Route::post('/auth/logout', function () {
    $idToken = request()->session()->get('keycloak_id_token');

    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    $logoutUrl = rtrim(config('services.keycloak.base_url'), '/').'/realms/'
        .rawurlencode(config('services.keycloak.realms')).'/protocol/openid-connect/logout';

    return response()->json(['redirect_url' => $logoutUrl.'?'.http_build_query([
        'client_id' => config('services.keycloak.client_id'),
        'post_logout_redirect_uri' => route('keycloak.logout.complete'),
        'id_token_hint' => $idToken,
    ], '', '&', PHP_QUERY_RFC3986)]);
})->name('keycloak.logout');

Route::get('/auth/logout/complete', fn () => redirect()->route('login'))
    ->name('keycloak.logout.complete');
