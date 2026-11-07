<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BranchCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.keycloak.base_url' => 'http://keycloak.test',
            'services.keycloak.realms' => 'infocell',
            'services.keycloak.admin_client_id' => 'infocell-api',
            'services.keycloak.admin_client_secret' => 'test-secret',
        ]);
    }

    public function test_guest_cannot_access_branch_crud_api(): void
    {
        $this->getJson('/api/v1/branches')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_delete_branches_and_keycloak_organizations(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/protocol/openid-connect/token')) {
                return Http::response(['access_token' => 'admin-token', 'expires_in' => 300]);
            }

            if ($request->method() === 'POST') {
                return Http::response('', 201, ['Location' => 'http://keycloak.test/admin/realms/infocell/organizations/organization-123']);
            }

            return Http::response('', 204);
        });

        $this->actingAs(User::factory()->create());
        $branch = $this->postJson('/api/v1/branches', [
            'name' => 'Filial Centro',
            'code' => 'CENTRO',
            'document' => '12.345.678/0001-90',
        ])->assertCreated()
            ->assertJsonPath('name', 'Filial Centro')
            ->assertJsonPath('code', 'CENTRO')
            ->assertJsonPath('active', true)
            ->assertJsonPath('keycloak_organization_id', 'organization-123')
            ->assertJsonPath('stock.branch_id', fn ($branchId) => $branchId !== null)
            ->json();

        $this->assertSame($branch['id'], $branch['stock']['branch_id']);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($branch['stock']['id']));
        $this->assertDatabaseHas('stocks', ['id' => $branch['stock']['id'], 'branch_id' => $branch['id']]);
        $this->assertSame(1, \App\Models\Stock::query()->where('branch_id', $branch['id'])->count());

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/admin/realms/infocell/organizations')
            && $request->hasHeader('Authorization', 'Bearer admin-token')
            && $request['name'] === 'Filial Centro'
            && $request['alias'] === 'branch-'.$branch['id']
            && $request['attributes']['branchCode'] === ['CENTRO']
            && $request['enabled'] === true);

        $this->getJson('/api/v1/branches')->assertOk()
            ->assertJsonPath('data.0.id', $branch['id']);
        $this->getJson('/api/v1/branches/'.$branch['id'])->assertOk()
            ->assertJsonPath('keycloak_organization_id', 'organization-123');
        $this->putJson('/api/v1/branches/'.$branch['id'], [
            'name' => 'Filial Centro Atualizada',
            'code' => 'CENTRO',
            'document' => null,
            'active' => false,
        ])->assertOk()
            ->assertJsonPath('name', 'Filial Centro Atualizada')
            ->assertJsonPath('active', false);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/admin/realms/infocell/organizations/organization-123')
            && $request['name'] === 'Filial Centro Atualizada'
            && $request['enabled'] === false);

        $this->deleteJson('/api/v1/branches/'.$branch['id'])->assertNoContent();
        $this->assertSoftDeleted('branches', ['id' => $branch['id']]);
        $this->assertSoftDeleted('stocks', ['id' => $branch['stock']['id']]);
        $this->assertDatabaseHas('branches', ['id' => $branch['id'], 'keycloak_organization_id' => 'organization-123']);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/admin/realms/infocell/organizations/organization-123'));
    }

    public function test_branch_is_not_persisted_if_keycloak_organization_creation_fails(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/protocol/openid-connect/token')) {
                return Http::response(['access_token' => 'admin-token', 'expires_in' => 300]);
            }

            return Http::response(['error' => 'forbidden'], 403);
        });

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/branches', ['name' => 'Filial Norte', 'code' => 'NORTE'])
            ->assertStatus(502);

        $this->assertDatabaseMissing('branches', ['code' => 'NORTE']);
    }

    public function test_branch_name_or_code_updates_and_deletion_roll_back_when_keycloak_fails(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/protocol/openid-connect/token')) {
                return Http::response(['access_token' => 'admin-token', 'expires_in' => 300]);
            }

            if ($request->method() === 'POST') {
                return Http::response('', 201, ['Location' => 'http://keycloak.test/admin/realms/infocell/organizations/organization-456']);
            }

            return Http::response(['error' => 'forbidden'], 403);
        });

        $this->actingAs(User::factory()->create());
        $branch = $this->postJson('/api/v1/branches', ['name' => 'Filial Leste', 'code' => 'LESTE'])->assertCreated()->json();

        $this->putJson('/api/v1/branches/'.$branch['id'], [
            'name' => 'Filial Leste Nova', 'code' => 'LESTE', 'active' => false,
        ])->assertStatus(502);
        $this->assertDatabaseHas('branches', [
            'id' => $branch['id'], 'name' => 'Filial Leste', 'active' => true, 'deleted_at' => null,
        ]);

        $this->deleteJson('/api/v1/branches/'.$branch['id'])->assertStatus(502);
        $this->assertDatabaseHas('branches', ['id' => $branch['id'], 'deleted_at' => null]);
    }

    public function test_branch_code_is_required_and_unique(): void
    {
        Http::fake(fn ($request) => str_ends_with($request->url(), '/token')
            ? Http::response(['access_token' => 'admin-token', 'expires_in' => 300])
            : Http::response('', 201, ['Location' => 'http://keycloak.test/admin/realms/infocell/organizations/organization-1']));

        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/branches', ['name' => 'Filial Sul', 'code' => 'SUL'])->assertCreated();
        $this->postJson('/api/v1/branches', ['name' => 'Filial Sul 2', 'code' => 'SUL'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/branches', ['name' => 'Filial sem código'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $branch = Branch::query()->where('code', 'SUL')->firstOrFail();
        $this->deleteJson('/api/v1/branches/'.$branch->id)->assertNoContent();
        $this->postJson('/api/v1/branches', ['name' => 'Filial Sul renovada', 'code' => 'SUL'])->assertCreated();
    }
}
