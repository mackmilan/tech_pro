<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class KeycloakOrganizationService
{
    public function create(string $name, string $alias, string $code, bool $enabled): string
    {
        $response = $this->adminRequest()->post($this->organizationsUrl(), [
            'name' => $name,
            'alias' => $alias,
            'description' => 'Filial Infocell '.$code,
            'attributes' => ['branchCode' => [$code], 'organizationType' => ['branch']],
            'enabled' => $enabled,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível criar a organização da filial no Keycloak.');
        }

        $location = $response->header('Location');
        $id = $location ? basename(parse_url($location, PHP_URL_PATH) ?: '') : null;

        if (! $id) {
            throw new RuntimeException('O Keycloak criou a organização sem retornar seu identificador.');
        }

        return $id;
    }

    public function update(string $organizationId, string $name, string $code, bool $enabled): void
    {
        $response = $this->adminRequest()->put($this->organizationUrl($organizationId), [
            'name' => $name,
            'description' => 'Filial Infocell '.$code,
            'attributes' => ['branchCode' => [$code], 'organizationType' => ['branch']],
            'enabled' => $enabled,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível atualizar a organização da filial no Keycloak.');
        }
    }

    public function delete(string $organizationId): void
    {
        $response = $this->adminRequest()->delete($this->organizationUrl($organizationId));

        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível excluir a organização da filial no Keycloak.');
        }
    }

    private function adminRequest(): PendingRequest
    {
        $token = Cache::remember('keycloak-admin-access-token', now()->addSeconds(240), function () {
            $response = Http::asForm()->post($this->tokenUrl(), [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.keycloak.admin_client_id'),
                'client_secret' => config('services.keycloak.admin_client_secret'),
            ]);

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException('Não foi possível autenticar o serviço administrativo no Keycloak.');
            }

            return $response->json('access_token');
        });

        return Http::acceptJson()->withToken($token)->timeout(10);
    }

    private function tokenUrl(): string
    {
        return $this->baseUrl().'/realms/'.rawurlencode(config('services.keycloak.realms')).'/protocol/openid-connect/token';
    }

    private function organizationsUrl(): string
    {
        return $this->baseUrl().'/admin/realms/'.rawurlencode(config('services.keycloak.realms')).'/organizations';
    }

    private function organizationUrl(string $organizationId): string
    {
        return $this->organizationsUrl().'/'.rawurlencode($organizationId);
    }

    private function baseUrl(): string
    {
        return rtrim(config('services.keycloak.base_url'), '/');
    }
}
