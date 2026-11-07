<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_keycloak_for_the_application(): void
    {
        $this->get('/')->assertRedirect(route('keycloak.redirect'));
    }

    public function test_login_route_redirects_directly_to_keycloak(): void
    {
        $this->get('/login')->assertRedirect(route('keycloak.redirect'));
    }

    public function test_callback_error_returns_to_a_page_without_restarting_oauth(): void
    {
        $this->get('/auth/callback?error=access_denied&error_description=Login+cancelado')
            ->assertRedirect(route('login.error'));

        $this->get(route('login.error'))->assertOk()->assertViewIs('auth.login')->assertSee('Login cancelado');
    }

    public function test_authenticated_user_can_load_the_application(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_logout_ends_the_keycloak_session_and_returns_to_a_logged_out_page(): void
    {
        $user = User::factory()->create();
        $response = $this->withSession(['keycloak_id_token' => 'test-id-token'])
            ->actingAs($user)
            ->post('/auth/logout');

        $response->assertOk();
        $logoutUrl = $response->json('redirect_url');
        $this->assertSame('http', parse_url($logoutUrl, PHP_URL_SCHEME));
        $this->assertSame('localhost:8080', parse_url($logoutUrl, PHP_URL_HOST).':'.parse_url($logoutUrl, PHP_URL_PORT));
        $this->assertSame('/realms/infocell/protocol/openid-connect/logout', parse_url($logoutUrl, PHP_URL_PATH));
        parse_str(parse_url($logoutUrl, PHP_URL_QUERY), $query);
        $this->assertSame('infocell-web', $query['client_id'] ?? null);
        $this->assertSame(route('keycloak.logout.complete'), $query['post_logout_redirect_uri'] ?? null);
        $this->assertSame('test-id-token', $query['id_token_hint'] ?? null);
        $this->assertGuest();

        $this->get(route('keycloak.logout.complete'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertRedirect(route('keycloak.redirect'));
    }

    public function test_keycloak_authorization_request_includes_s256_pkce_parameters(): void
    {
        config([
            'services.keycloak.client_id' => 'infocell-web',
            'services.keycloak.client_secret' => 'test-secret',
            'services.keycloak.redirect' => 'http://localhost/auth/callback',
            'services.keycloak.base_url' => 'http://localhost:8080',
            'services.keycloak.realms' => 'infocell',
        ]);

        $response = $this->get('/auth/redirect');
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertNotEmpty($query['code_challenge'] ?? null);
    }

    public function test_keycloak_error_callback_redirects_without_throwing_invalid_state(): void
    {
        $this->get('/auth/callback?error=invalid_request&error_description=Missing+parameter%3A+code_challenge_method&state=old-state')
            ->assertRedirect(route('login.error'));
    }

    public function test_keycloak_callback_with_missing_state_fails_gracefully(): void
    {
        $this->get('/auth/callback?code=authorization-code&state=unmatched-state')
            ->assertRedirect(route('login.error'));
    }

    public function test_keycloak_token_exchange_failure_returns_a_safe_login_error_instead_of_500(): void
    {
        $provider = \Mockery::mock();
        $provider->shouldReceive('user')->once()->andThrow(new \GuzzleHttp\Exception\ClientException(
            'unauthorized_client',
            new \GuzzleHttp\Psr7\Request('POST', 'http://localhost:8080/realms/infocell/protocol/openid-connect/token'),
            new \GuzzleHttp\Psr7\Response(401, [], '{"error":"unauthorized_client"}')
        ));
        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->once()->with('keycloak')->andReturn($provider);

        $this->get('/auth/callback?code=authorization-code&state=valid-state')
            ->assertRedirect(route('login.error'))
            ->assertSessionHasErrors('keycloak');
    }

    public function test_keycloak_user_without_email_can_authenticate_using_subject(): void
    {
        $provider = \Mockery::mock();
        $provider->shouldReceive('user')->once()->andReturn(\Laravel\Socialite\Two\User::fake([
            'id' => 'keycloak-subject-123',
            'email' => null,
            'name' => null,
            'nickname' => 'vendedor',
        ]));
        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->once()->with('keycloak')->andReturn($provider);

        $this->get('/auth/callback?code=authorization-code&state=valid-state')->assertRedirect('/');

        $this->assertDatabaseHas('users', [
            'keycloak_id' => 'keycloak-subject-123',
            'email' => null,
            'name' => 'vendedor',
        ]);
        $this->assertAuthenticated();
    }

    public function test_guest_cannot_access_protected_api(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }
}
