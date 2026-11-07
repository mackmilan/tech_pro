<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_technicians_api(): void
    {
        $this->getJson('/api/v1/technicians')->assertUnauthorized();
        $this->postJson('/api/v1/technicians', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_delete_technician(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // 1. Create
        $response = $this->postJson('/api/v1/technicians', [
            'name' => 'Lucas Manutenção',
            'email' => 'lucas@assistencia.com',
            'phone' => '(11) 98765-4321',
            'document' => '123.456.789-00',
            'specialty' => 'Smartphones Apple & Troca de Telas',
            'commission_rate' => 12.5,
            'active' => true,
            'notes' => 'Especialista em solda e placa',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Lucas Manutenção')
            ->assertJsonPath('specialty', 'Smartphones Apple & Troca de Telas')
            ->assertJsonPath('commission_rate', '12.50')
            ->assertJsonPath('active', true);

        $technicianId = $response->json('id');
        $this->assertDatabaseHas('technicians', ['id' => $technicianId, 'name' => 'Lucas Manutenção']);

        // 2. List
        $this->getJson('/api/v1/technicians')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Lucas Manutenção');

        // 3. Show
        $this->getJson("/api/v1/technicians/{$technicianId}")
            ->assertOk()
            ->assertJsonPath('id', $technicianId)
            ->assertJsonPath('phone', '(11) 98765-4321');

        // 4. Update
        $this->putJson("/api/v1/technicians/{$technicianId}", [
            'name' => 'Lucas Silva (Sênior)',
            'specialty' => 'Microssoldagem e Apple',
            'commission_rate' => 15.0,
            'active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Lucas Silva (Sênior)')
            ->assertJsonPath('specialty', 'Microssoldagem e Apple')
            ->assertJsonPath('commission_rate', '15.00')
            ->assertJsonPath('active', false);

        // 5. Delete
        $this->deleteJson("/api/v1/technicians/{$technicianId}")
            ->assertNoContent();

        $this->assertSoftDeleted('technicians', ['id' => $technicianId]);
    }

    public function test_technician_search_and_active_filters(): void
    {
        $this->actingAs(User::factory()->create());

        Technician::create(['name' => 'Carlos Souza', 'specialty' => 'Android', 'active' => true]);
        Technician::create(['name' => 'Ana Clara', 'specialty' => 'Apple', 'active' => true]);
        Technician::create(['name' => 'Roberto Inativo', 'specialty' => 'Notebooks', 'active' => false]);

        // Search by name
        $this->getJson('/api/v1/technicians?search=Carlos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Carlos Souza');

        // Search by specialty
        $this->getJson('/api/v1/technicians?search=Apple')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ana Clara');

        // Filter active
        $this->getJson('/api/v1/technicians?active=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Filter inactive
        $this->getJson('/api/v1/technicians?active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Roberto Inativo');
    }

    public function test_technician_validations(): void
    {
        $this->actingAs(User::factory()->create());

        // Name is required
        $this->postJson('/api/v1/technicians', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        // Invalid email & invalid commission rate
        $this->postJson('/api/v1/technicians', [
            'name' => 'Teste',
            'email' => 'invalid-email',
            'commission_rate' => 150, // exceeds max 100
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'commission_rate']);
    }

    public function test_technician_cannot_be_deleted_when_linked_to_service_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Matriz', 'code' => 'MTZ-01']);
        $customer = Customer::create(['name' => 'Maria Silva']);
        $technician = Technician::create(['name' => 'Técnico Especialista']);

        ServiceOrder::create([
            'number' => 'OS-202611-TECH-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'technician_id' => $technician->id,
            'device_model' => 'iPhone 14',
            'defect_reported' => 'Não liga',
        ]);

        $this->deleteJson("/api/v1/technicians/{$technician->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['technician']);

        $this->assertDatabaseHas('technicians', ['id' => $technician->id, 'deleted_at' => null]);
    }
}
