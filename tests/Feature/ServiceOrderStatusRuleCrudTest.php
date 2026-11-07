<?php

namespace Tests\Feature;

use App\Models\ServiceOrderStatusRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderStatusRuleCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_rules_api(): void
    {
        $this->getJson('/api/v1/service-order-status-rules')->assertUnauthorized();
        $this->postJson('/api/v1/service-order-status-rules', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_list_rules_with_defaults(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/service-order-status-rules');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'status',
                        'name',
                        'description',
                        'color',
                        'allow_view',
                        'allow_edit',
                        'allow_execute',
                        'allow_cancel',
                        'allow_print',
                        'allowed_transitions',
                        'active',
                    ],
                ],
            ]);

        // Default seeded rules exist
        $statuses = collect($response->json('data'))->pluck('status');
        $this->assertTrue($statuses->contains('open'));
        $this->assertTrue($statuses->contains('completed'));
        $this->assertTrue($statuses->contains('delivered'));
        $this->assertTrue($statuses->contains('cancelled'));
    }

    public function test_authenticated_user_can_create_show_update_and_delete_rule(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // 1. Create custom status rule
        $response = $this->postJson('/api/v1/service-order-status-rules', [
            'status' => 'waiting_budget',
            'name' => 'Aguardando Orçamento',
            'description' => 'Aguardando precificação e análise de peças.',
            'color' => 'amber',
            'allow_view' => true,
            'allow_edit' => true,
            'allow_execute' => false,
            'allow_cancel' => true,
            'allow_print' => true,
            'allowed_transitions' => ['in_analysis', 'approved', 'cancelled'],
            'active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'waiting_budget')
            ->assertJsonPath('name', 'Aguardando Orçamento')
            ->assertJsonPath('allow_view', true)
            ->assertJsonPath('allow_edit', true)
            ->assertJsonPath('allow_execute', false)
            ->assertJsonPath('allow_cancel', true)
            ->assertJsonPath('allowed_transitions', ['in_analysis', 'approved', 'cancelled']);

        $ruleId = $response->json('id');
        $this->assertDatabaseHas('service_order_status_rules', [
            'id' => $ruleId,
            'status' => 'waiting_budget',
        ]);

        // 2. Show
        $showResponse = $this->getJson("/api/v1/service-order-status-rules/{$ruleId}");
        $showResponse->assertOk()
            ->assertJsonPath('id', $ruleId)
            ->assertJsonPath('status', 'waiting_budget');

        // 3. Update
        $updateResponse = $this->putJson("/api/v1/service-order-status-rules/{$ruleId}", [
            'name' => 'Aguardando Orçamento do Fornecedor',
            'allow_edit' => false,
            'allowed_transitions' => ['in_analysis', 'cancelled'],
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('name', 'Aguardando Orçamento do Fornecedor')
            ->assertJsonPath('allow_edit', false)
            ->assertJsonPath('allowed_transitions', ['in_analysis', 'cancelled']);

        $this->assertDatabaseHas('service_order_status_rules', [
            'id' => $ruleId,
            'name' => 'Aguardando Orçamento do Fornecedor',
            'allow_edit' => false,
        ]);

        // 4. Delete
        $deleteResponse = $this->deleteJson("/api/v1/service-order-status-rules/{$ruleId}");
        $deleteResponse->assertNoContent();

        $this->assertSoftDeleted('service_order_status_rules', ['id' => $ruleId]);
    }

    public function test_cannot_create_duplicate_active_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // 'open' already exists from migration seed
        $response = $this->postJson('/api/v1/service-order-status-rules', [
            'status' => 'open',
            'name' => 'Aberta Duplicada',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_seed_defaults_endpoint_restores_rules(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Delete 'open' rule
        ServiceOrderStatusRule::where('status', 'open')->delete();
        $this->assertSoftDeleted('service_order_status_rules', ['status' => 'open']);

        // Run seed-defaults
        $response = $this->postJson('/api/v1/service-order-status-rules/seed-defaults');

        $response->assertOk()
            ->assertJsonPath('message', 'Regras de status restauradas com sucesso.');

        $this->assertDatabaseHas('service_order_status_rules', [
            'status' => 'open',
            'deleted_at' => null,
        ]);
    }

    public function test_filter_by_search_and_active(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $searchResponse = $this->getJson('/api/v1/service-order-status-rules?search=Aberta');
        $searchResponse->assertOk();
        $items = $searchResponse->json('data');
        $this->assertNotEmpty($items);
        $this->assertEquals('open', $items[0]['status']);

        $allResponse = $this->getJson('/api/v1/service-order-status-rules?all=1');
        $allResponse->assertOk();
        $this->assertArrayHasKey('data', $allResponse->json());
        $this->assertGreaterThanOrEqual(8, count($allResponse->json('data')));
    }
}
