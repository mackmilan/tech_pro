<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_customers_api(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $this->postJson('/api/v1/customers', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_delete_customer(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // 1. Create
        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Maria Oliveira',
            'document' => '123.456.789-10',
            'email' => 'maria@exemplo.com',
            'phone' => '(11) 98765-4321',
            'source' => 'WhatsApp',
            'active' => true,
            'notes' => 'Cliente preferencial de reparos Apple.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Maria Oliveira')
            ->assertJsonPath('document', '123.456.789-10')
            ->assertJsonPath('email', 'maria@exemplo.com')
            ->assertJsonPath('phone', '(11) 98765-4321')
            ->assertJsonPath('source', 'WhatsApp')
            ->assertJsonPath('active', true)
            ->assertJsonPath('notes', 'Cliente preferencial de reparos Apple.');

        $customerId = $response->json('id');
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'name' => 'Maria Oliveira']);

        // 2. List
        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Maria Oliveira');

        // 3. Show
        $this->getJson("/api/v1/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('id', $customerId)
            ->assertJsonPath('document', '123.456.789-10');

        // 4. Update
        $this->putJson("/api/v1/customers/{$customerId}", [
            'name' => 'Maria Oliveira Santos',
            'document' => '123.456.789-10',
            'email' => 'maria.santos@exemplo.com',
            'phone' => '(11) 99999-8888',
            'source' => 'Indicação',
            'active' => false,
            'notes' => 'Mudou de telefone.',
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Maria Oliveira Santos')
            ->assertJsonPath('email', 'maria.santos@exemplo.com')
            ->assertJsonPath('phone', '(11) 99999-8888')
            ->assertJsonPath('source', 'Indicação')
            ->assertJsonPath('active', false);

        // 5. Delete (Soft delete)
        $this->deleteJson("/api/v1/customers/{$customerId}")
            ->assertNoContent();

        $this->assertSoftDeleted('customers', ['id' => $customerId]);
    }

    public function test_customer_search_and_active_filters(): void
    {
        $this->actingAs(User::factory()->create());

        Customer::create([
            'name' => 'Carlos Ferreira',
            'document' => '111.222.333-44',
            'email' => 'carlos@teste.com',
            'phone' => '(11) 91111-2222',
            'source' => 'Balcão',
            'active' => true,
        ]);
        Customer::create([
            'name' => 'Fernanda Lima',
            'document' => '555.666.777-88',
            'email' => 'fernanda@teste.com',
            'phone' => '(21) 93333-4444',
            'source' => 'Instagram',
            'active' => true,
        ]);
        Customer::create([
            'name' => 'Roberto Inativo',
            'document' => '999.888.777-66',
            'email' => 'roberto@teste.com',
            'phone' => '(31) 95555-6666',
            'source' => 'Google',
            'active' => false,
        ]);

        // Search by name
        $this->getJson('/api/v1/customers?search=Carlos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Carlos Ferreira');

        // Search by document
        $this->getJson('/api/v1/customers?search=555.666')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Fernanda Lima');

        // Search by phone
        $this->getJson('/api/v1/customers?search=95555')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Roberto Inativo');

        // Filter active = true
        $activeRes = $this->getJson('/api/v1/customers?active=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $names = collect($activeRes->json('data'))->pluck('name')->all();
        $this->assertContains('Carlos Ferreira', $names);
        $this->assertContains('Fernanda Lima', $names);
        $this->assertNotContains('Roberto Inativo', $names);

        // Filter active = false
        $this->getJson('/api/v1/customers?active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Roberto Inativo');
    }

    public function test_customer_validations(): void
    {
        $this->actingAs(User::factory()->create());

        // Name is required
        $this->postJson('/api/v1/customers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        // Email format validation
        $this->postJson('/api/v1/customers', [
            'name' => 'João Silva',
            'email' => 'invalido',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_customer_cannot_be_deleted_when_linked_to_service_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Filial Centro', 'code' => 'CENTRO']);
        $customer = Customer::create(['name' => 'Ana Clara', 'active' => true]);

        ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-2026-0001',
            'status' => 'pending',
            'priority' => 'normal',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13',
            'device_color' => 'Preto',
            'defect_reported' => 'Tela quebrada',
        ]);

        $this->deleteJson("/api/v1/customers/{$customer->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }
}

