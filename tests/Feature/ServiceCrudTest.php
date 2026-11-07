<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_services_api(): void
    {
        $this->getJson('/api/v1/services')->assertUnauthorized();
        $this->postJson('/api/v1/services', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_delete_service(): void
    {
        $this->actingAs(User::factory()->create());

        $payload = [
            'name' => 'Troca de Conector de Carga',
            'code' => 'SRV-CON-01',
            'description' => 'Substituição do conector dock de carga USB-C / Lightning',
            'price' => 120.00,
            'estimated_minutes' => 45,
            'warranty_days' => 90,
            'active' => true,
        ];

        $createResponse = $this->postJson('/api/v1/services', $payload)
            ->assertCreated()
            ->assertJsonPath('name', 'Troca de Conector de Carga')
            ->assertJsonPath('code', 'SRV-CON-01')
            ->assertJsonPath('price', '120.00')
            ->assertJsonPath('estimated_minutes', 45)
            ->assertJsonPath('warranty_days', 90)
            ->assertJsonPath('active', true);

        $serviceId = $createResponse->json('id');

        // Show
        $this->getJson("/api/v1/services/{$serviceId}")
            ->assertOk()
            ->assertJsonPath('id', $serviceId)
            ->assertJsonPath('name', 'Troca de Conector de Carga');

        // List with search
        $this->getJson('/api/v1/services?search=Conector')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $serviceId);

        // Update
        $this->putJson("/api/v1/services/{$serviceId}", [
            'name' => 'Troca de Conector USB-C',
            'price' => 140.00,
            'estimated_minutes' => 60,
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Troca de Conector USB-C')
            ->assertJsonPath('price', '140.00')
            ->assertJsonPath('estimated_minutes', 60);

        // Delete
        $this->deleteJson("/api/v1/services/{$serviceId}")
            ->assertNoContent();

        $this->assertSoftDeleted('services', ['id' => $serviceId]);
    }

    public function test_code_is_unique_and_reusable_after_soft_delete(): void
    {
        $this->actingAs(User::factory()->create());

        $serviceA = Service::create([
            'name' => 'Limpeza Química',
            'code' => 'LIMPEZA',
            'price' => 80.00,
        ]);

        $this->postJson('/api/v1/services', [
            'name' => 'Outra Limpeza',
            'code' => 'LIMPEZA',
            'price' => 90.00,
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $serviceA->delete();

        $this->postJson('/api/v1/services', [
            'name' => 'Outra Limpeza',
            'code' => 'LIMPEZA',
            'price' => 90.00,
        ])->assertCreated();
    }

    public function test_service_cannot_be_deleted_when_linked_to_service_order(): void
    {
        $this->actingAs(User::factory()->create());

        $service = Service::create([
            'name' => 'Banho Químico Desoxidação',
            'code' => 'DESOX',
            'price' => 180.00,
        ]);

        $branch = Branch::create(['name' => 'Filial Centro', 'code' => 'FC-01']);
        $customer = Customer::create(['name' => 'Maria Silva']);

        $order = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-202611-TEST',
            'status' => 'open',
            'device_model' => 'Galaxy S22',
            'defect_reported' => 'Molhou na piscina',
        ]);

        ServiceOrderItem::create([
            'service_order_id' => $order->id,
            'service_id' => $service->id,
            'type' => 'service',
            'description' => 'Desoxidação em ultrassom',
            'quantity' => 1,
            'unit_price' => 180.00,
            'total' => 180.00,
        ]);

        $this->deleteJson("/api/v1/services/{$service->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service']);

        $this->assertNotSoftDeleted('services', ['id' => $service->id]);
    }

    public function test_service_validations(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/services', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price']);

        $this->postJson('/api/v1/services', [
            'name' => 'Serviço Inválido',
            'price' => -10,
            'estimated_minutes' => 0,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'estimated_minutes']);
    }
}

