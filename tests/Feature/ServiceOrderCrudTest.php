<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_service_order_api(): void
    {
        $this->getJson('/api/v1/service-orders')->assertUnauthorized();
        $this->postJson('/api/v1/service-orders', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_service_order_with_items(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Matriz', 'code' => 'MTZ-01']);
        $customer = Customer::create([
            'name' => 'Carlos Alberto',
            'document' => '123.456.789-00',
            'phone' => '(11) 98765-4321',
            'email' => 'carlos@example.com',
        ]);
        $technician = Technician::create(['name' => 'Técnico Especialista']);
        $productPart = Product::create([
            'name' => 'Tela iPhone 13 OLED',
            'sku' => 'TELA-IP13-OLED',
            'category' => 'Peças',
            'sale_price' => 450,
        ]);
        $laborService = Service::create([
            'name' => 'Mão de obra de substituição de display',
            'code' => 'SRV-DISPLAY',
            'price' => 150,
            'warranty_days' => 90,
        ]);

        $payload = [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'technician_id' => $technician->id,
            'number' => 'OS-202611-001',
            'status' => 'in_progress',
            'priority' => 'high',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13 128GB',
            'device_imei_serial' => '356789012345678',
            'device_color' => 'Azul',
            'device_password' => '123456',
            'device_condition' => 'Tela trincada, pequenos riscos na lateral',
            'accessories' => 'Capa de silicone, sem carregador',
            'defect_reported' => 'Aparelho caiu no chão e o display parou de responder ao toque',
            'defect_diagnosed' => 'Módulo de display quebrado. Placa mãe e Face ID íntegros',
            'solution_applied' => null,
            'technical_notes' => 'Avisar cliente sobre bateria a 82%',
            'discount' => 20.00,
            'notes' => 'Cliente retira amanhã às 18h',
            'items' => [
                [
                    'type' => 'product',
                    'product_id' => $productPart->id,
                    'description' => 'Tela iPhone 13 OLED Premium',
                    'quantity' => 1,
                    'unit_price' => 450.00,
                    'discount' => 0,
                    'notes' => 'Peça de primeira linha',
                ],
                [
                    'type' => 'service',
                    'product_id' => null,
                    'service_id' => $laborService->id,
                    'description' => 'Mão de obra de substituição de display',
                    'quantity' => 1,
                    'unit_price' => 150.00,
                    'discount' => 0,
                    'notes' => 'Inclui vedação contra poeira',
                ],
            ],
        ];

        // Services: 150.00
        // Products: 450.00
        // Total: 450 + 150 - 20 = 580.00
        $response = $this->postJson('/api/v1/service-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('number', 'OS-202611-001')
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('priority', 'high')
            ->assertJsonPath('device_brand', 'Apple')
            ->assertJsonPath('device_model', 'iPhone 13 128GB')
            ->assertJsonPath('defect_reported', 'Aparelho caiu no chão e o display parou de responder ao toque')
            ->assertJsonPath('services_amount', '150.00')
            ->assertJsonPath('products_amount', '450.00')
            ->assertJsonPath('discount', '20.00')
            ->assertJsonPath('total', '580.00')
            ->assertJsonPath('branch.id', $branch->id)
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('technician.id', $technician->id);

        $this->assertDatabaseHas('service_orders', [
            'number' => 'OS-202611-001',
            'device_model' => 'iPhone 13 128GB',
            'status' => 'in_progress',
        ]);

        $this->assertDatabaseHas('service_order_items', [
            'description' => 'Tela iPhone 13 OLED Premium',
            'type' => 'product',
            'total' => 450.00,
        ]);
        $this->assertDatabaseHas('service_order_items', [
            'description' => 'Mão de obra de substituição de display',
            'type' => 'service',
            'service_id' => $laborService->id,
            'total' => 150.00,
        ]);
    }

    public function test_service_order_generates_number_automatically_when_omitted(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Filial Sul', 'code' => 'SUL-01']);
        $customer = Customer::create(['name' => 'Maria Souza']);

        $response = $this->postJson('/api/v1/service-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'device_model' => 'Galaxy S21',
            'defect_reported' => 'Não carrega',
        ])->assertCreated();

        $number = $response->json('number');
        $this->assertStringStartsWith('OS-', $number);
    }

    public function test_user_can_list_service_orders_with_filters(): void
    {
        $this->actingAs(User::factory()->create());

        $branchA = Branch::create(['name' => 'Filial Alpha', 'code' => 'AL-01']);
        $branchB = Branch::create(['name' => 'Filial Beta', 'code' => 'BE-01']);

        $customerA = Customer::create(['name' => 'João Silva', 'phone' => '(11) 99999-1111']);
        $customerB = Customer::create(['name' => 'Renata Lima', 'phone' => '(11) 98888-2222']);

        ServiceOrder::create([
            'branch_id' => $branchA->id,
            'customer_id' => $customerA->id,
            'number' => 'OS-AAA-001',
            'status' => 'open',
            'priority' => 'low',
            'device_brand' => 'Motorola',
            'device_model' => 'Moto G84',
            'defect_reported' => 'Microfone chiando',
        ]);

        ServiceOrder::create([
            'branch_id' => $branchB->id,
            'customer_id' => $customerB->id,
            'number' => 'OS-BBB-002',
            'status' => 'completed',
            'priority' => 'urgent',
            'device_brand' => 'Xiaomi',
            'device_model' => 'Redmi Note 13',
            'defect_reported' => 'Bateria estufada',
        ]);

        // Search by number
        $this->getJson('/api/v1/service-orders?search=OS-AAA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'OS-AAA-001');

        // Search by customer name
        $this->getJson('/api/v1/service-orders?search=Renata')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'OS-BBB-002');

        // Search by device
        $this->getJson('/api/v1/service-orders?search=Moto')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_model', 'Moto G84');

        // Filter by branch
        $this->getJson("/api/v1/service-orders?branch_id={$branchB->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'OS-BBB-002');

        // Filter by status
        $this->getJson('/api/v1/service-orders?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'OS-AAA-001');

        // Filter by priority
        $this->getJson('/api/v1/service-orders?priority=urgent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'OS-BBB-002');
    }

    public function test_user_can_show_service_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-01']);
        $customer = Customer::create(['name' => 'Lucas Ferreira']);
        $order = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-SHOW-01',
            'status' => 'approved',
            'device_model' => 'iPhone 11',
            'defect_reported' => 'Câmera traseira trêmula',
        ]);

        $order->items()->create([
            'type' => 'service',
            'description' => 'Troca da câmera traseira',
            'quantity' => 1,
            'unit_price' => 300,
            'total' => 300,
        ]);

        $this->getJson("/api/v1/service-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('id', $order->id)
            ->assertJsonPath('number', 'OS-SHOW-01')
            ->assertJsonPath('customer.name', 'Lucas Ferreira')
            ->assertJsonCount(1, 'items');
    }

    public function test_user_can_update_service_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-01']);
        $customer = Customer::create(['name' => 'Lucas Ferreira']);
        $order = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-UPD-01',
            'status' => 'open',
            'device_model' => 'iPhone 11',
            'defect_reported' => 'Sem som',
            'total' => 0,
        ]);

        $payload = [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'device_model' => 'iPhone 11',
            'defect_reported' => 'Sem som no alto-falante',
            'status' => 'completed',
            'solution_applied' => 'Limpeza e desobstrução da grade de som',
            'items' => [
                [
                    'type' => 'service',
                    'description' => 'Higienização e desobstrução acústica',
                    'quantity' => 1,
                    'unit_price' => 90.00,
                    'discount' => 10.00,
                ],
            ],
        ];

        $response = $this->putJson("/api/v1/service-orders/{$order->id}", $payload)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('solution_applied', 'Limpeza e desobstrução da grade de som')
            ->assertJsonPath('services_amount', '80.00')
            ->assertJsonPath('total', '80.00');

        $this->assertNotNull($response->json('completed_at'));
        $this->assertDatabaseHas('service_orders', [
            'id' => $order->id,
            'status' => 'completed',
            'total' => 80.00,
        ]);
    }

    public function test_user_cannot_delete_service_order_via_api(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-01']);
        $customer = Customer::create(['name' => 'Lucas Ferreira']);
        $order = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-DEL-01',
            'device_model' => 'iPad Air',
            'defect_reported' => 'Conector quebrado',
        ]);

        $this->deleteJson("/api/v1/service-orders/{$order->id}")
            ->assertMethodNotAllowed();

        $this->assertDatabaseHas('service_orders', ['id' => $order->id, 'deleted_at' => null]);
    }

    public function test_user_can_cancel_service_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-01']);
        $customer = Customer::create(['name' => 'Lucas Ferreira']);
        $order = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-CANC-01',
            'status' => 'in_progress',
            'device_model' => 'iPad Air',
            'defect_reported' => 'Conector quebrado',
        ]);
        $order->items()->create([
            'type' => 'service',
            'description' => 'Troca de conector',
            'quantity' => 1,
            'unit_price' => 200,
            'total' => 200,
        ]);

        $this->postJson("/api/v1/service-orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('id', $order->id)
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('service_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'deleted_at' => null,
        ]);
    }

    public function test_user_cannot_cancel_delivered_or_already_cancelled_service_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-01']);
        $customer = Customer::create(['name' => 'Lucas Ferreira']);

        $deliveredOrder = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-DELIV-01',
            'status' => 'delivered',
            'device_model' => 'iPad Air',
            'defect_reported' => 'Conector quebrado',
        ]);

        $this->postJson("/api/v1/service-orders/{$deliveredOrder->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $cancelledOrder = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-CAN-01',
            'status' => 'cancelled',
            'device_model' => 'iPad Air',
            'defect_reported' => 'Conector quebrado',
        ]);

        $this->postJson("/api/v1/service-orders/{$cancelledOrder->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_user_cannot_update_delivered_or_cancelled_service_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-01']);
        $customer = Customer::create(['name' => 'Lucas Ferreira']);

        $deliveredOrder = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-DELIV-UPD',
            'status' => 'delivered',
            'device_model' => 'iPhone 11',
            'defect_reported' => 'Sem som',
        ]);

        $payload = [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'device_model' => 'iPhone 11',
            'defect_reported' => 'Sem som no alto-falante',
        ];

        $this->putJson("/api/v1/service-orders/{$deliveredOrder->id}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $cancelledOrder = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-CAN-UPD',
            'status' => 'cancelled',
            'device_model' => 'iPhone 11',
            'defect_reported' => 'Sem som',
        ]);

        $this->putJson("/api/v1/service-orders/{$cancelledOrder->id}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_user_cannot_execute_cancelled_service_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-01']);
        $customer = Customer::create(['name' => 'Lucas Ferreira']);

        $cancelledOrder = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-CAN-EXEC',
            'status' => 'cancelled',
            'device_model' => 'iPhone 11',
            'defect_reported' => 'Sem som',
        ]);

        $this->postJson("/api/v1/service-orders/{$cancelledOrder->id}/execute", [
            'status' => 'in_progress',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_service_order_validations(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/service-orders', [])
            ->assertJsonValidationErrors(['branch_id', 'customer_id', 'device_model', 'defect_reported']);

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-01']);
        $customer = Customer::create(['name' => 'Lucas Ferreira']);

        // Invalid status
        $this->postJson('/api/v1/service-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'device_model' => 'iPhone 13',
            'defect_reported' => 'Defeito',
            'status' => 'invalid-status',
        ])->assertJsonValidationErrors(['status']);

        // Excessive discount
        $this->postJson('/api/v1/service-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'device_model' => 'iPhone 13',
            'defect_reported' => 'Defeito',
            'discount' => 100.00,
            'items' => [
                [
                    'type' => 'service',
                    'description' => 'Serviço',
                    'quantity' => 1,
                    'unit_price' => 50.00,
                ],
            ],
        ])->assertJsonValidationErrors(['discount']);
    }

    public function test_setting_delivered_status_sets_delivered_at(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-01']);
        $customer = Customer::create(['name' => 'Lucas Ferreira']);

        $response = $this->postJson('/api/v1/service-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'device_model' => 'Galaxy S23',
            'defect_reported' => 'Troca de bateria',
            'status' => 'delivered',
        ])->assertCreated();

        $this->assertNotNull($response->json('delivered_at'));
        $this->assertNotNull($response->json('completed_at'));
    }

    public function test_user_can_execute_service_order_and_record_technical_progress(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Matriz', 'code' => 'MTZ-01']);
        $customer = Customer::create(['name' => 'Renato Russo']);
        $technician = Technician::create(['name' => 'Bancada Especialista']);

        $order = ServiceOrder::create([
            'number' => 'OS-202611-EXEC-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'device_model' => 'iPad Pro 11',
            'defect_reported' => 'Não carrega',
            'status' => 'open',
        ]);

        // Execute step 1: set to in_progress with diagnosis and technician
        $response = $this->postJson("/api/v1/service-orders/{$order->id}/execute", [
            'technician_id' => $technician->id,
            'status' => 'in_progress',
            'defect_diagnosed' => 'Conector USB-C danificado e oxidação leve',
            'technical_notes' => 'Iniciado banho químico e ressolda',
            'checklists' => [
                [
                    'item_name' => 'Conector de carga',
                    'stage' => 'exit',
                    'status' => 'ok',
                    'notes' => 'Trocado conector com sucesso',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('technician.id', $technician->id)
            ->assertJsonPath('defect_diagnosed', 'Conector USB-C danificado e oxidação leve')
            ->assertJsonPath('technical_notes', 'Iniciado banho químico e ressolda')
            ->assertJsonPath('checklists.0.item_name', 'Conector de carga')
            ->assertJsonPath('checklists.0.status', 'ok');

        // Execute step 2: complete order
        $completeResponse = $this->postJson("/api/v1/service-orders/{$order->id}/execute", [
            'status' => 'completed',
            'solution_applied' => 'Substituição do conector de carga e limpeza ultrassônica',
        ]);

        $completeResponse->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('solution_applied', 'Substituição do conector de carga e limpeza ultrassônica');

        $this->assertNotNull($completeResponse->json('completed_at'));
    }
}

