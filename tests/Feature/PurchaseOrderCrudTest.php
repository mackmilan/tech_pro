<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCondition;
use App\Models\PurchaseOrder;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_purchase_order_api(): void
    {
        $this->getJson('/api/v1/purchase-orders')->assertUnauthorized();
        $this->postJson('/api/v1/purchase-orders', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_purchase_order_with_items(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Matriz', 'code' => 'MTZ-01']);
        $supplier = Supplier::create(['name' => 'Distribuidora Tech', 'document' => '11.222.333/0001-44']);
        $productA = Product::create(['name' => 'iPhone 15 Pro', 'sku' => 'IP15P-128', 'category' => 'Celulares', 'sale_price' => 6000]);
        $productB = Product::create(['name' => 'Cabo USB-C', 'sku' => 'CABO-USBC', 'category' => 'Acessórios', 'sale_price' => 80]);

        $payload = [
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-2026-0001',
            'status' => 'ordered',
            'order_date' => '2026-11-06',
            'expected_delivery_date' => '2026-11-15',
            'shipping_cost' => 50.00,
            'discount' => 100.00,
            'notes' => 'Pedido inicial para reposição de estoque',
            'items' => [
                [
                    'product_id' => $productA->id,
                    'quantity' => 2,
                    'unit_cost' => 4500.00,
                    'notes' => 'Cor titânio natural',
                ],
                [
                    'product_id' => $productB->id,
                    'quantity' => 10,
                    'unit_cost' => 25.00,
                    'notes' => null,
                ],
            ],
        ];

        // Subtotal: (2 * 4500) + (10 * 25) = 9000 + 250 = 9250.00
        // Total: 9250 + 50 - 100 = 9200.00
        $response = $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('number', 'PC-2026-0001')
            ->assertJsonPath('status', 'ordered')
            ->assertJsonPath('subtotal', '9250.00')
            ->assertJsonPath('shipping_cost', '50.00')
            ->assertJsonPath('discount', '100.00')
            ->assertJsonPath('total', '9200.00')
            ->assertJsonPath('supplier.name', 'Distribuidora Tech')
            ->assertJsonPath('branch.name', 'Matriz')
            ->assertJsonCount(2, 'items');

        $orderId = $response->json('id');
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $orderId,
            'number' => 'PC-2026-0001',
            'total' => 9200.00,
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $orderId,
            'product_id' => $productA->id,
            'quantity' => 2,
            'unit_cost' => 4500.00,
            'total' => 9000.00,
        ]);
    }

    public function test_purchase_order_generates_number_automatically_when_omitted(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Filial Sul', 'code' => 'SUL-01']);
        $supplier = Supplier::create(['name' => 'Fornecedor Acessórios']);
        $product = Product::create(['name' => 'Carregador 20W', 'sku' => 'CHG-20W', 'category' => 'Acessórios', 'sale_price' => 120]);

        $response = $this->postJson('/api/v1/purchase-orders', [
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-06',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'unit_cost' => 40.00,
                ],
            ],
        ])->assertCreated();

        $number = $response->json('number');
        $this->assertNotEmpty($number);
        $this->assertStringStartsWith('PC-', $number);
        $this->assertSame('draft', $response->json('status'));
        $this->assertSame('200.00', $response->json('total'));
    }

    public function test_user_can_list_purchase_orders_with_filters(): void
    {
        $this->actingAs(User::factory()->create());

        $branch1 = Branch::create(['name' => 'Centro', 'code' => 'CTR-01']);
        $branch2 = Branch::create(['name' => 'Shopping', 'code' => 'SHP-01']);
        $supplier1 = Supplier::create(['name' => 'Alpha Fornecedor']);
        $supplier2 = Supplier::create(['name' => 'Beta Peças']);
        $product = Product::create(['name' => 'Tela iPhone 13', 'sku' => 'TEL-IP13', 'category' => 'Peças', 'sale_price' => 500]);

        $order1 = PurchaseOrder::create([
            'branch_id' => $branch1->id,
            'supplier_id' => $supplier1->id,
            'number' => 'PC-ALPHA-01',
            'status' => 'draft',
            'order_date' => '2026-11-01',
            'subtotal' => 500,
            'total' => 500,
        ]);
        $order1->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 250, 'total' => 500]);

        $order2 = PurchaseOrder::create([
            'branch_id' => $branch2->id,
            'supplier_id' => $supplier2->id,
            'number' => 'PC-BETA-02',
            'status' => 'received',
            'order_date' => '2026-11-02',
            'subtotal' => 1000,
            'total' => 1000,
        ]);
        $order2->items()->create(['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 250, 'total' => 1000]);

        // List all
        $this->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Filter by branch
        $this->getJson('/api/v1/purchase-orders?branch_id='.$branch1->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'PC-ALPHA-01');

        // Filter by supplier
        $this->getJson('/api/v1/purchase-orders?supplier_id='.$supplier2->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'PC-BETA-02');

        // Filter by status
        $this->getJson('/api/v1/purchase-orders?status=received')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'PC-BETA-02');

        // Search by number or supplier name
        $this->getJson('/api/v1/purchase-orders?search=ALPHA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'PC-ALPHA-01');
    }

    public function test_user_can_show_purchase_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-02']);
        $supplier = Supplier::create(['name' => 'Distribuidora Global']);
        $product = Product::create(['name' => 'Bateria S23', 'sku' => 'BAT-S23', 'category' => 'Peças', 'sale_price' => 200]);

        $order = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-SHOW-01',
            'status' => 'draft',
            'order_date' => '2026-11-05',
            'subtotal' => 300,
            'total' => 300,
        ]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 100, 'total' => 300]);

        $this->getJson('/api/v1/purchase-orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('id', $order->id)
            ->assertJsonPath('number', 'PC-SHOW-01')
            ->assertJsonPath('supplier.name', 'Distribuidora Global')
            ->assertJsonPath('items.0.product.name', 'Bateria S23');
    }

    public function test_user_can_update_purchase_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-03']);
        $supplier = Supplier::create(['name' => 'Fornecedor Original']);
        $supplier2 = Supplier::create(['name' => 'Fornecedor Novo']);
        $productA = Product::create(['name' => 'Pelicula 3D', 'sku' => 'PEL-3D', 'category' => 'Acessórios', 'sale_price' => 30]);
        $productB = Product::create(['name' => 'Capa Silicone', 'sku' => 'CAP-SIL', 'category' => 'Acessórios', 'sale_price' => 45]);

        $order = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-UPD-01',
            'status' => 'draft',
            'order_date' => '2026-11-01',
            'subtotal' => 100,
            'total' => 100,
        ]);
        $order->items()->create(['product_id' => $productA->id, 'quantity' => 10, 'unit_cost' => 10, 'total' => 100]);

        $updatePayload = [
            'branch_id' => $branch->id,
            'supplier_id' => $supplier2->id,
            'number' => 'PC-UPD-01',
            'status' => 'approved',
            'order_date' => '2026-11-02',
            'expected_delivery_date' => '2026-11-10',
            'shipping_cost' => 20,
            'discount' => 10,
            'notes' => 'Atualizado para novo fornecedor',
            'items' => [
                [
                    'product_id' => $productB->id,
                    'quantity' => 20,
                    'unit_cost' => 15.00,
                ],
            ],
        ];

        // Subtotal: 20 * 15 = 300. Total: 300 + 20 - 10 = 310
        $this->putJson('/api/v1/purchase-orders/'.$order->id, $updatePayload)
            ->assertOk()
            ->assertJsonPath('supplier_id', $supplier2->id)
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('subtotal', '300.00')
            ->assertJsonPath('total', '310.00')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.product_id', $productB->id);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $order->id,
            'supplier_id' => $supplier2->id,
            'status' => 'approved',
            'total' => 310.00,
        ]);
    }

    public function test_user_cannot_delete_purchase_order_via_api(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-04']);
        $supplier = Supplier::create(['name' => 'Fornecedor Del']);

        $order = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-DEL-01',
            'status' => 'draft',
            'order_date' => '2026-11-01',
            'subtotal' => 150,
            'total' => 150,
        ]);

        $this->deleteJson('/api/v1/purchase-orders/'.$order->id)->assertMethodNotAllowed();
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'deleted_at' => null]);
    }

    public function test_user_can_cancel_purchase_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-04']);
        $supplier = Supplier::create(['name' => 'Fornecedor Canc']);
        $product = Product::create(['name' => 'Fone BT', 'sku' => 'FON-BT', 'category' => 'Acessórios', 'sale_price' => 150]);

        $order = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-CANC-01',
            'status' => 'ordered',
            'order_date' => '2026-11-01',
            'subtotal' => 150,
            'total' => 150,
        ]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 50, 'total' => 150]);

        $this->postJson("/api/v1/purchase-orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('id', $order->id)
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'deleted_at' => null,
        ]);
    }

    public function test_user_cannot_cancel_already_received_or_cancelled_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-04']);
        $supplier = Supplier::create(['name' => 'Fornecedor Test']);

        $receivedOrder = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-REC-01',
            'status' => 'received',
            'order_date' => '2026-11-01',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $this->postJson("/api/v1/purchase-orders/{$receivedOrder->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $cancelledOrder = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-CAN-01',
            'status' => 'cancelled',
            'order_date' => '2026-11-01',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $this->postJson("/api/v1/purchase-orders/{$cancelledOrder->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_user_cannot_update_received_or_cancelled_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-04']);
        $supplier = Supplier::create(['name' => 'Fornecedor Test']);
        $product = Product::create(['name' => 'Cabo USB-C', 'sku' => 'CAB-USBC', 'category' => 'Cabos', 'sale_price' => 30]);

        $receivedOrder = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-REC-UPD',
            'status' => 'received',
            'order_date' => '2026-11-01',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $payload = [
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-02',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 15]],
        ];

        $this->putJson("/api/v1/purchase-orders/{$receivedOrder->id}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $cancelledOrder = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-CAN-UPD',
            'status' => 'cancelled',
            'order_date' => '2026-11-01',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $this->putJson("/api/v1/purchase-orders/{$cancelledOrder->id}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_purchase_order_validations(): void
    {
        $this->actingAs(User::factory()->create());

        // Missing required fields
        $this->postJson('/api/v1/purchase-orders', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'supplier_id', 'order_date', 'items']);

        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR-05']);
        $supplier = Supplier::create(['name' => 'Fornecedor Val']);
        $product = Product::create(['name' => 'Carregador', 'sku' => 'CHG-VAL', 'category' => 'Acessórios', 'sale_price' => 50]);

        // Invalid item quantity and unit cost
        $this->postJson('/api/v1/purchase-orders', [
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-06',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 0,
                    'unit_cost' => -10,
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity', 'items.0.unit_cost']);

        // Invalid status
        $this->postJson('/api/v1/purchase-orders', [
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-06',
            'status' => 'invalid_status',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 10,
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Discount cannot exceed subtotal + shipping
        $this->postJson('/api/v1/purchase-orders', [
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-06',
            'discount' => 200,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 50,
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['discount']);

        // Duplicate order number
        PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-DUPLICATE',
            'status' => 'draft',
            'order_date' => '2026-11-06',
            'subtotal' => 50,
            'total' => 50,
        ]);

        $this->postJson('/api/v1/purchase-orders', [
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-DUPLICATE',
            'order_date' => '2026-11-06',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 50,
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['number']);
    }

    public function test_user_can_receive_purchase_order_with_stock_attributes(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Filial Recebimento', 'code' => 'REC-01']);
        $stock = $branch->stock;
        $location = StockLocation::create([
            'stock_id' => $stock->id,
            'name' => 'Depósito Geral',
            'code' => 'DEP-01',
            'type' => 'storage',
        ]);
        $condition = ProductCondition::create(['name' => 'Novo']);
        $supplier = Supplier::create(['name' => 'Fornecedor Tech']);

        // Create product with stock attributes (one per-unit attribute IMEI and one shared attribute)
        $product = Product::create([
            'name' => 'Smartphone Galaxy',
            'sku' => 'GAL-S24',
            'category' => 'Celulares',
            'sale_price' => 4000,
        ]);

        $imeiAttr = ProductAttribute::create([
            'name' => 'IMEI',
            'entity_type' => 'estoque',
            'type' => 'texto',
            'required' => true,
            'per_unit' => true,
        ]);

        $colorAttr = ProductAttribute::create([
            'name' => 'Cor',
            'entity_type' => 'estoque',
            'type' => 'selecao_unica',
            'options' => ['Preto', 'Branco'],
            'required' => true,
            'per_unit' => false,
        ]);

        $order = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-REC-001',
            'status' => 'ordered',
            'order_date' => '2026-11-06',
            'subtotal' => 6000,
            'total' => 6000,
        ]);

        $orderItem = $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_cost' => 3000,
            'total' => 6000,
        ]);

        $payload = [
            'stock_location_id' => $location->id,
            'condition_id' => $condition->id,
            'items' => [
                [
                    'purchase_order_item_id' => $orderItem->id,
                    'attributes' => [
                        [
                            'product_attribute_id' => $colorAttr->id,
                            'value' => 'Preto',
                        ],
                    ],
                    'units' => [
                        [
                            'attributes' => [
                                [
                                    'product_attribute_id' => $imeiAttr->id,
                                    'value' => '359123456789001',
                                ],
                            ],
                        ],
                        [
                            'attributes' => [
                                [
                                    'product_attribute_id' => $imeiAttr->id,
                                    'value' => '359123456789002',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson("/api/v1/purchase-orders/{$order->id}/receive", $payload)
            ->assertOk()
            ->assertJsonPath('status', 'received')
            ->assertJsonPath('id', $order->id);

        $this->assertNotNull($response->json('received_at'));

        // Assert purchase order status is updated in database
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $order->id,
            'status' => 'received',
        ]);

        // Assert StockMovement was created
        $this->assertDatabaseHas('stock_movements', [
            'stock_id' => $stock->id,
            'stock_location_id' => $location->id,
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 2,
            'reference' => 'PC-REC-001',
            'source' => 'fornecedor',
        ]);

        // Assert 2 StockUnits were created with IMEI attributes
        $units = StockUnit::where('stock_location_id', $location->id)->get();
        $this->assertCount(2, $units);
        $this->assertSame('359123456789001', $units[0]->attributes[$imeiAttr->id]);
        $this->assertSame('359123456789002', $units[1]->attributes[$imeiAttr->id]);
        $this->assertSame($condition->id, $units[0]->condition_id);
        $this->assertSame('fornecedor', $units[0]->source);
    }

    public function test_user_cannot_receive_already_received_or_cancelled_order(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Filial Val', 'code' => 'VAL-01']);
        $supplier = Supplier::create(['name' => 'Fornecedor Val']);

        $receivedOrder = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-ALREADY-REC',
            'status' => 'received',
            'order_date' => '2026-11-06',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $this->postJson("/api/v1/purchase-orders/{$receivedOrder->id}/receive", ['items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $cancelledOrder = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-CANCELLED',
            'status' => 'cancelled',
            'order_date' => '2026-11-06',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $this->postJson("/api/v1/purchase-orders/{$cancelledOrder->id}/receive", ['items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_receive_validates_required_stock_attributes_per_unit(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::create(['name' => 'Filial Attr', 'code' => 'ATT-01']);
        $stock = $branch->stock;
        $location = StockLocation::create([
            'stock_id' => $stock->id,
            'name' => 'Depósito 1',
            'code' => 'DEP-1',
            'type' => 'storage',
        ]);
        $condition = ProductCondition::create(['name' => 'Novo']);
        $supplier = Supplier::create(['name' => 'Fornecedor Tech']);

        $product = Product::create([
            'name' => 'Celular IMEI Requerido',
            'sku' => 'CEL-IMEI-REQ',
            'category' => 'Celulares',
            'sale_price' => 2000,
        ]);

        ProductAttribute::create([
            'name' => 'IMEI Obrigatório',
            'entity_type' => 'estoque',
            'type' => 'texto',
            'required' => true,
            'per_unit' => true,
        ]);

        $order = PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'PC-ATTR-REQ',
            'status' => 'ordered',
            'order_date' => '2026-11-06',
            'subtotal' => 2000,
            'total' => 2000,
        ]);

        $orderItem = $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 1000,
            'total' => 1000,
        ]);

        // Attempt receiving without the required IMEI unit attribute
        $payload = [
            'stock_location_id' => $location->id,
            'condition_id' => $condition->id,
            'items' => [
                [
                    'purchase_order_item_id' => $orderItem->id,
                    'units' => [
                        [
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson("/api/v1/purchase-orders/{$order->id}/receive", $payload)
            ->assertUnprocessable();
    }
}


