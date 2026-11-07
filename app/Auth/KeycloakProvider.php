<?php

namespace App\Auth;

use SocialiteProviders\Keycloak\Provider as BaseKeycloakProvider;

class KeycloakProvider extends BaseKeycloakProvider
{
    public function redirect()
    {
        $this->enablePKCE();

        return parent::redirect();
    }

    public function getAccessTokenResponse($code)
    {
        $response = parent::getAccessTokenResponse($code);

        if (! empty($response['id_token'])) {
            $this->request->session()->put('keycloak_id_token', $response['id_token']);
        }

        return $response;
    }

    public function user()
    {
        // Socialite creates a fresh provider instance on callback; enable PKCE
        // again so the saved verifier is included in the token exchange.
        $this->enablePKCE();

        return parent::user();
    }
}
