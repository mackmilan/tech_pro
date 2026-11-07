<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceChecklist;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceChecklistCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_service_checklists_api(): void
    {
        $this->getJson('/api/v1/service-checklists')->assertUnauthorized();
        $this->postJson('/api/v1/service-checklists', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_delete_checklist(): void
    {
        $this->actingAs(User::factory()->create());

        $payload = [
            'name' => 'Touch Screen e Sensibilidade',
            'category' => 'Tela e Toque',
            'description' => 'Testar toque em toda a área e verificar toque fantasma',
            'stage' => 'both',
            'is_required' => true,
            'sort_order' => 1,
            'active' => true,
        ];

        $response = $this->postJson('/api/v1/service-checklists', $payload)
            ->assertCreated()
            ->assertJsonPath('name', 'Touch Screen e Sensibilidade')
            ->assertJsonPath('category', 'Tela e Toque')
            ->assertJsonPath('stage', 'both')
            ->assertJsonPath('is_required', true)
            ->assertJsonPath('sort_order', 1);

        $checklistId = $response->json('id');

        // Show
        $this->getJson("/api/v1/service-checklists/{$checklistId}")
            ->assertOk()
            ->assertJsonPath('id', $checklistId)
            ->assertJsonPath('name', 'Touch Screen e Sensibilidade');

        // List with filter
        $this->getJson('/api/v1/service-checklists?search=Touch')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $checklistId);

        // Stage filter
        $this->getJson('/api/v1/service-checklists?stage=entry')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Update
        $this->putJson("/api/v1/service-checklists/{$checklistId}", [
            'name' => 'Toque e Multitoque',
            'category' => 'Tela',
            'stage' => 'entry',
            'is_required' => false,
            'sort_order' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Toque e Multitoque')
            ->assertJsonPath('category', 'Tela')
            ->assertJsonPath('stage', 'entry')
            ->assertJsonPath('is_required', false)
            ->assertJsonPath('sort_order', 2);

        // Delete
        $this->deleteJson("/api/v1/service-checklists/{$checklistId}")
            ->assertNoContent();

        $this->assertSoftDeleted('service_checklists', ['id' => $checklistId]);
    }

    public function test_checklist_can_be_linked_to_a_specific_service(): void
    {
        $this->actingAs(User::factory()->create());

        $service = Service::create([
            'name' => 'Troca de Câmera Traseira',
            'price' => 200,
        ]);

        $response = $this->postJson('/api/v1/service-checklists', [
            'service_id' => $service->id,
            'name' => 'Foco Óptico e Estabilizador',
            'category' => 'Câmeras',
            'stage' => 'exit',
            'is_required' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('service.id', $service->id)
            ->assertJsonPath('service.name', 'Troca de Câmera Traseira');

        $this->getJson("/api/v1/service-checklists?service_id={$service->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $response->json('id'));
    }

    public function test_service_order_can_record_checklist_inspections(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Matriz', 'code' => 'MTZ-01']);
        $customer = Customer::create(['name' => 'Carlos']);
        $checkItem = ServiceChecklist::create([
            'name' => 'Câmera Frontal Face ID',
            'category' => 'Câmeras',
            'stage' => 'both',
        ]);

        $payload = [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'device_model' => 'iPhone 13',
            'defect_reported' => 'Câmera com defeito',
            'checklists' => [
                [
                    'service_checklist_id' => $checkItem->id,
                    'item_name' => 'Câmera Frontal Face ID',
                    'category' => 'Câmeras',
                    'stage' => 'entry',
                    'status' => 'defect',
                    'notes' => 'Face ID desativado por dano físico',
                ],
                [
                    'service_checklist_id' => null,
                    'item_name' => 'Tampa Traseira',
                    'category' => 'Carcaça',
                    'stage' => 'entry',
                    'status' => 'ok',
                    'notes' => 'Sem riscos',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/service-orders', $payload)
            ->assertCreated()
            ->assertJsonCount(2, 'checklists')
            ->assertJsonPath('checklists.0.status', 'defect')
            ->assertJsonPath('checklists.0.notes', 'Face ID desativado por dano físico')
            ->assertJsonPath('checklists.1.status', 'ok');

        $orderId = $response->json('id');

        $this->assertDatabaseHas('service_order_checklists', [
            'service_order_id' => $orderId,
            'item_name' => 'Câmera Frontal Face ID',
            'status' => 'defect',
        ]);

        // Update checklists
        $this->putJson("/api/v1/service-orders/{$orderId}", [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'device_model' => 'iPhone 13',
            'defect_reported' => 'Câmera com defeito',
            'checklists' => [
                [
                    'service_checklist_id' => $checkItem->id,
                    'item_name' => 'Câmera Frontal Face ID',
                    'category' => 'Câmeras',
                    'stage' => 'exit',
                    'status' => 'ok',
                    'notes' => 'Sensor reparado com sucesso',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'checklists')
            ->assertJsonPath('checklists.0.stage', 'exit')
            ->assertJsonPath('checklists.0.status', 'ok')
            ->assertJsonPath('checklists.0.notes', 'Sensor reparado com sucesso');
    }
}

