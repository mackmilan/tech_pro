<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCondition;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_locations_and_movements_are_owned_by_stock_instead_of_branch(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial Centro', 'code' => 'CENTRO']);
        $stock = $branch->stock;
        $product = Product::create([
            'sku' => 'STOCK-1', 'name' => 'Celular', 'category' => 'Celulares',
            'cost_price' => 100, 'sale_price' => 150,
        ]);

        $location = $this->postJson('/api/v1/stock-locations', [
            'stock_id' => $stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage',
        ])->assertCreated()->assertJsonPath('stock_id', $stock->id)->assertJsonMissingPath('branch_id')->json();

        $otherStock = Branch::create(['name' => 'Filial Norte', 'code' => 'NORTE'])->stock;
        $otherLocation = $this->postJson('/api/v1/stock-locations', [
            'stock_id' => $otherStock->id, 'name' => 'Depósito Norte', 'code' => 'DEP', 'type' => 'storage',
        ])->assertCreated()->json();

        $condition = ProductCondition::create(['name' => 'Novo']);
        $otherMovement = $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $otherStock->id, 'stock_location_id' => $otherLocation['id'], 'product_id' => $product->id,
            'type' => 'in', 'quantity' => 2, 'condition_id' => $condition->id, 'unit_cost' => 100,
        ])->assertCreated()->json();

        $this->getJson('/api/v1/stock-locations?stock_id='.$stock->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.stock_id', $stock->id);

        $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $stock->id, 'stock_location_id' => $location['id'], 'product_id' => $product->id,
            'type' => 'in', 'quantity' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['condition_id', 'unit_cost']);

        $movement = $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $stock->id, 'stock_location_id' => $location['id'], 'product_id' => $product->id,
            'type' => 'in', 'quantity' => 5, 'condition_id' => $condition->id, 'unit_cost' => 75,
        ])->assertCreated()->assertJsonPath('stock_id', $stock->id)->assertJsonMissingPath('branch_id')->json();

        $this->assertSame($stock->id, StockLocation::findOrFail($location['id'])->stock->id);
        $this->assertSame($stock->id, StockMovement::findOrFail($movement['id'])->stock->id);
        $this->assertSame(1, Stock::query()->where('branch_id', $branch->id)->count());
        $this->assertTrue(Schema::hasColumn('stock_locations', 'stock_id'));
        $this->assertTrue(Schema::hasColumn('stock_movements', 'stock_id'));
        $this->assertFalse(Schema::hasColumn('stock_locations', 'branch_id'));
        $this->assertFalse(Schema::hasColumn('stock_movements', 'branch_id'));
        $this->assertSame('stocks', collect(Schema::getForeignKeys('stock_items'))->firstWhere('columns', ['stock_id'])['foreign_table']);
        $this->assertSame('stock_items', collect(Schema::getForeignKeys('stock_units'))->firstWhere('columns', ['stock_item_id'])['foreign_table']);
        $this->assertSame('stock_units', collect(Schema::getForeignKeys('sale_items'))->firstWhere('columns', ['stock_unit_id'])['foreign_table']);
        $this->assertTrue(Schema::hasColumn('product_attributes', 'per_unit'));

        $this->assertSame('stocks', collect(Schema::getForeignKeys('stock_movements'))->firstWhere('columns', ['stock_id'])['foreign_table']);
        $this->assertSame('stock_locations', collect(Schema::getForeignKeys('stock_movements'))->firstWhere('columns', ['stock_location_id'])['foreign_table']);
        $this->assertDatabaseHas('stock_locations', ['id' => $location['id'], 'stock_id' => $stock->id]);
        $this->assertDatabaseHas('stock_movements', ['id' => $movement['id'], 'stock_id' => $stock->id]);
        $this->assertDatabaseHas('stock_movements', ['id' => $otherMovement['id'], 'stock_id' => $otherStock->id]);
        $this->assertDatabaseMissing('stock_movements', ['id' => $movement['id'], 'stock_id' => $otherStock->id]);
        $this->getJson('/api/v1/stock-movements?stock_id='.$stock->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.stock_id', $stock->id);
    }

    public function test_stock_entry_requires_required_attribute_values_and_rejects_invalid_attribute_values(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial valida entrada', 'code' => 'VALENT']);
        $product = Product::create([
            'sku' => 'STOCK-ENTRY-REQUIRED', 'name' => 'Celular', 'category' => 'Celulares',
            'cost_price' => 100, 'sale_price' => 150,
        ]);
        $location = StockLocation::create([
            'stock_id' => $branch->stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage',
        ]);
        $condition = ProductCondition::create(['name' => 'Novo']);
        $attribute = ProductAttribute::create([
            'name' => 'Cor', 'entity_type' => 'estoque', 'type' => 'selecao_unica',
            'options' => ['Preto', 'Azul'], 'required' => true,
        ]);
        $payload = [
            'stock_id' => $branch->stock->id, 'stock_location_id' => $location->id, 'product_id' => $product->id,
            'type' => 'in', 'quantity' => 1, 'condition_id' => $condition->id, 'unit_cost' => 100,
        ];

        $this->postJson('/api/v1/stock-movements', [...$payload, 'attributes' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('attributes');
        $this->postJson('/api/v1/stock-movements', [...$payload, 'attributes' => [
            ['product_attribute_id' => $attribute->id, 'value' => 'Verde'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('attributes.0.value');
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $product->id]);
        $this->assertDatabaseMissing('stock_items', ['product_id' => $product->id]);
    }

    public function test_stock_attribute_can_store_a_distinct_required_value_for_each_entered_unit(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial Unidade', 'code' => 'UNID']);
        $product = Product::create([
            'sku' => 'STOCK-UNIT-1', 'name' => 'Celular', 'category' => 'Celulares',
            'sale_price' => 150,
        ]);
        $location = StockLocation::create([
            'stock_id' => $branch->stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage',
        ]);
        $condition = ProductCondition::create(['name' => 'Novo']);
        $attribute = $this->postJson('/api/v1/product-attributes', [
            'name' => 'IMEI', 'entity_type' => 'estoque', 'type' => 'texto', 'options' => [],
            'required' => true, 'differentiate_stock' => false, 'per_unit' => true, 'category_ids' => [],
        ])->assertCreated()->assertJsonPath('per_unit', true)->json();

        $this->getJson('/api/v1/products/'.$product->id.'/stock-attributes')
            ->assertOk()->assertJsonPath('data.0.per_unit', true);
        $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $branch->stock->id, 'stock_location_id' => $location->id, 'product_id' => $product->id,
            'type' => 'in', 'quantity' => 2, 'condition_id' => $condition->id, 'unit_cost' => 100,
            'attributes' => [['product_attribute_id' => $attribute['id'], 'value' => ['IMEI-1']]],
        ])->assertUnprocessable()->assertJsonValidationErrors('attributes.0.value');
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $product->id]);
        $this->assertDatabaseMissing('stock_items', ['product_id' => $product->id]);
        $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $branch->stock->id, 'stock_location_id' => $location->id, 'product_id' => $product->id,
            'type' => 'in', 'quantity' => 2, 'condition_id' => $condition->id, 'unit_cost' => 100,
            'attributes' => [['product_attribute_id' => $attribute['id'], 'value' => ['IMEI-1', 'IMEI-2']]],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_items', [
            'stock_id' => $branch->stock->id, 'product_id' => $product->id, 'quantity' => 2,
        ]);
        $unitAttributes = StockUnit::query()->whereHas('stockItem', fn ($query) => $query->where('stock_id', $branch->stock->id))->orderBy('id')->get();
        $this->assertCount(2, $unitAttributes);
        $this->assertSame('IMEI-1', $unitAttributes[0]->attributes[$attribute['id']]);
        $this->assertSame('IMEI-2', $unitAttributes[1]->attributes[$attribute['id']]);
        $this->getJson('/api/v1/stock-items?stock_id='.$branch->stock->id)
            ->assertOk()->assertJsonPath('data.0.units.0.attributes.'.$attribute['id'], 'IMEI-1')
            ->assertJsonPath('data.0.units.1.attributes.'.$attribute['id'], 'IMEI-2');
    }

    public function test_per_unit_identifiers_are_selected_and_consumed_once_during_sale(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        $branch = Branch::create(['name' => 'Filial Venda Unitária', 'code' => 'VUNIT']);
        $product = Product::create(['sku' => 'SALE-UNIT-1', 'name' => 'Celular', 'category' => 'Celulares', 'category_id' => $category->id, 'sale_price' => 100]);
        $attribute = ProductAttribute::create(['name' => 'IMEI', 'entity_type' => 'estoque', 'type' => 'texto', 'per_unit' => true]);
        $attribute->categories()->attach($category->id);
        $location = StockLocation::create(['stock_id' => $branch->stock->id, 'name' => 'Loja', 'code' => 'LOJA', 'type' => 'storage']);
        $item = StockItem::create(['stock_id' => $branch->stock->id, 'product_id' => $product->id, 'quantity' => 2]);
        StockMovement::create(['stock_id' => $branch->stock->id, 'stock_location_id' => $location->id, 'product_id' => $product->id, 'type' => 'in', 'quantity' => 2, 'condition_id' => ProductCondition::create(['name' => 'Novo'])->id, 'unit_cost' => 90]);
        $unitA = StockUnit::create(['stock_item_id' => $item->id, 'attributes' => [$attribute->id => 'IMEI-A']]);
        $unitB = StockUnit::create(['stock_item_id' => $item->id, 'attributes' => [$attribute->id => 'IMEI-B']]);

        $payload = ['stock_id' => $branch->stock->id, 'items' => [[
            'product_id' => $product->id, 'stock_location_id' => $location->id, 'quantity' => 1,
        ]]];
        $this->postJson('/api/v1/sales', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/v1/sales', [...$payload, 'items' => [[...$payload['items'][0], 'stock_unit_ids' => [$unitA->id]]]])
            ->assertCreated()->assertJsonPath('items.0.stock_unit_id', $unitA->id);
        $this->assertDatabaseHas('stock_units', ['id' => $unitA->id, 'active' => false]);
        $this->assertDatabaseHas('stock_units', ['id' => $unitB->id, 'active' => true]);
        $this->postJson('/api/v1/sales', [...$payload, 'items' => [[...$payload['items'][0], 'stock_unit_ids' => [$unitA->id]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stock_movements', ['stock_id' => $branch->stock->id, 'product_id' => $product->id, 'type' => 'out', 'quantity' => 1]);
    }

    public function test_product_stock_differentiating_attributes_can_vary_per_received_unit(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        $branch = Branch::create(['name' => 'Filial Variante Unitária', 'code' => 'VAR-UNIT']);
        $product = Product::create(['sku' => 'VAR-UNIT-1', 'name' => 'Celular', 'category' => 'Celulares', 'category_id' => $category->id, 'sale_price' => 150]);
        $location = StockLocation::create(['stock_id' => $branch->stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage']);
        $condition = ProductCondition::create(['name' => 'Novo']);
        $attribute = ProductAttribute::create([
            'name' => 'Cor', 'entity_type' => 'produto', 'type' => 'selecao_multipla',
            'options' => ['Preto', 'Azul'], 'required' => true, 'differentiate_stock' => true,
        ]);
        $attribute->categories()->attach($category->id);
        $payload = [
            'stock_id' => $branch->stock->id, 'stock_location_id' => $location->id, 'product_id' => $product->id,
            'type' => 'in', 'quantity' => 2,
            'units' => [
                ['condition_id' => $condition->id, 'unit_cost' => 100, 'attributes' => [['product_attribute_id' => $attribute->id, 'value' => 'Preto']]],
                ['condition_id' => $condition->id, 'unit_cost' => 100, 'attributes' => [['product_attribute_id' => $attribute->id, 'value' => 'Azul']]],
            ],
        ];
        $this->postJson('/api/v1/stock-movements', $payload)->assertCreated();

        $items = StockItem::query()->where('stock_id', $branch->stock->id)->where('product_id', $product->id)->with('attributeValues', 'units')->get();
        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(['Preto', 'Azul'], $items->map(fn ($item) => $item->attributeValues->firstWhere('product_attribute_id', $attribute->id)?->value)->all());
        $this->assertSame([1, 1], $items->pluck('quantity')->sort()->values()->all());
        $this->assertEqualsCanonicalizing(['Preto', 'Azul'], $items->flatMap(fn ($item) => $item->units->map(fn ($unit) => $unit->attributes[$attribute->id] ?? null))->all());
        $this->getJson('/api/v1/stock-items?stock_id='.$branch->stock->id)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_product_stock_differentiating_attribute_rejects_multiple_values_per_unit(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Acessórios']);
        $branch = Branch::create(['name' => 'Filial Invalidade', 'code' => 'VAR-INVALID']);
        $product = Product::create(['sku' => 'VAR-INVALID-1', 'name' => 'Fone', 'category' => 'Acessórios', 'category_id' => $category->id, 'sale_price' => 50]);
        $location = StockLocation::create(['stock_id' => $branch->stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage']);
        $condition = ProductCondition::create(['name' => 'Novo']);
        $attribute = ProductAttribute::create(['name' => 'Cor', 'entity_type' => 'produto', 'type' => 'selecao_multipla', 'options' => ['Preto', 'Azul'], 'required' => true, 'differentiate_stock' => true]);
        $attribute->categories()->attach($category->id);

        $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $branch->stock->id, 'stock_location_id' => $location->id, 'product_id' => $product->id, 'type' => 'in', 'quantity' => 1,
            'units' => [['condition_id' => $condition->id, 'unit_cost' => 10, 'attributes' => [['product_attribute_id' => $attribute->id, 'value' => ['Preto', 'Azul']]]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('units.0.attributes.0.value');
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $product->id]);
        $this->assertDatabaseMissing('stock_items', ['product_id' => $product->id]);
    }

    public function test_inbound_stock_movement_persists_condition_cost_and_unit_attributes_per_item(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial por Unidade', 'code' => 'UNIT-COST']);
        $product = Product::create(['sku' => 'UNIT-COST-1', 'name' => 'Celular', 'category' => 'Celulares', 'sale_price' => 150]);
        $location = StockLocation::create(['stock_id' => $branch->stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage']);
        $newCondition = ProductCondition::create(['name' => 'Novo']);
        $usedCondition = ProductCondition::create(['name' => 'Seminovo']);
        $attribute = ProductAttribute::create(['name' => 'IMEI', 'entity_type' => 'estoque', 'type' => 'texto', 'required' => true, 'per_unit' => true]);

        $response = $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $branch->stock->id,
            'stock_location_id' => $location->id,
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 2,
            'units' => [
                ['condition_id' => $newCondition->id, 'unit_cost' => 100, 'attributes' => [['product_attribute_id' => $attribute->id, 'value' => 'IMEI-1']]],
                ['condition_id' => $usedCondition->id, 'unit_cost' => 80, 'attributes' => [['product_attribute_id' => $attribute->id, 'value' => 'IMEI-2']]],
            ],
        ])->assertCreated()->json();

        $units = StockUnit::query()->whereHas('stockItem', fn ($query) => $query->where('product_id', $product->id))->get();
        $this->assertCount(2, $units);
        $this->assertEqualsCanonicalizing([
            [$newCondition->id, '100.00', 'IMEI-1'],
            [$usedCondition->id, '80.00', 'IMEI-2'],
        ], $units->map(fn ($unit) => [$unit->condition_id, number_format((float) $unit->unit_cost, 2, '.', ''), $unit->attributes[$attribute->id]])->all());
        $this->assertDatabaseHas('stock_movements', ['id' => $response['id'], 'quantity' => 2]);
        $this->getJson('/api/v1/stock-items?stock_id='.$branch->stock->id)
            ->assertOk()->assertJsonPath('data.0.units.0.condition_id', $newCondition->id)
            ->assertJsonPath('data.0.units.0.unit_cost', '100.00')
            ->assertJsonPath('data.0.units.0.attributes.'.$attribute->id, 'IMEI-1');
    }

    public function test_inbound_stock_movement_persists_unit_fields_when_units_payload_is_used(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial entrada por unidade', 'code' => 'IN-UNIT']);
        $product = Product::create(['sku' => 'IN-UNIT-1', 'name' => 'Celular', 'category' => 'Celulares', 'sale_price' => 150]);
        $location = StockLocation::create(['stock_id' => $branch->stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage']);
        $condition = ProductCondition::create(['name' => 'Novo']);

        $movement = $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $branch->stock->id,
            'stock_location_id' => $location->id,
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 1,
            'units' => [['condition_id' => $condition->id, 'unit_cost' => 87.50]],
        ])->assertCreated()
            ->assertJsonPath('condition_id', $condition->id)
            ->assertJsonPath('unit_cost', '87.50')
            ->json();

        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement['id'], 'condition_id' => $condition->id, 'unit_cost' => 87.50,
        ]);
    }

    public function test_inbound_stock_movement_persists_condition_and_unit_cost(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial Centro', 'code' => 'CENTRO']);
        $product = Product::create([
            'sku' => 'STOCK-IN-1', 'name' => 'Celular', 'category' => 'Celulares',
            'cost_price' => 100, 'sale_price' => 150,
        ]);
        $condition = ProductCondition::create(['name' => 'Seminovo']);
        $location = StockLocation::create([
            'stock_id' => $branch->stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage',
        ]);

        $movement = $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $branch->stock->id,
            'stock_location_id' => $location->id,
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 3,
            'condition_id' => $condition->id,
            'unit_cost' => 87.50,
        ])->assertCreated()
            ->assertJsonPath('condition_id', $condition->id)
            ->assertJsonPath('unit_cost', '87.50')
            ->json();

        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement['id'], 'condition_id' => $condition->id, 'unit_cost' => 87.50,
        ]);
        $this->assertDatabaseHas('stock_items', [
            'stock_id' => $branch->stock->id, 'product_id' => $product->id, 'quantity' => 3,
        ]);
    }

    public function test_stock_movement_rejects_a_location_from_another_stock(): void
    {
        $this->actingAs(User::factory()->create());
        $firstStock = Branch::create(['name' => 'Filial A', 'code' => 'A'])->stock;
        $secondStock = Branch::create(['name' => 'Filial B', 'code' => 'B'])->stock;
        $product = Product::create([
            'sku' => 'STOCK-2', 'name' => 'Tablet', 'category' => 'Tablets',
            'cost_price' => 100, 'sale_price' => 150,
        ]);
        $location = StockLocation::create([
            'stock_id' => $firstStock->id, 'name' => 'Depósito A', 'code' => 'DEP', 'type' => 'storage',
        ]);

        $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $secondStock->id,
            'stock_location_id' => $location->id,
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 1,
            'condition_id' => ProductCondition::create(['name' => 'Novo'])->id,
            'unit_cost' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('stock_location_id');
    }

    public function test_inbound_stock_movement_accepts_stock_differentiating_attribute_in_shared_payload_when_units_provided(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        $branch = Branch::create(['name' => 'Filial Variação Compartilhada', 'code' => 'VAR-SHARED']);
        $product = Product::create(['sku' => 'VAR-SHARED-1', 'name' => 'Celular', 'category' => 'Celulares', 'category_id' => $category->id, 'sale_price' => 150]);
        $location = StockLocation::create(['stock_id' => $branch->stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage']);
        $condition = ProductCondition::create(['name' => 'Novo']);
        $attribute = ProductAttribute::create([
            'name' => 'Cor', 'entity_type' => 'estoque', 'type' => 'selecao_unica',
            'options' => ['Roxo', 'Azul'], 'required' => true, 'differentiate_stock' => true,
        ]);
        $attribute->categories()->attach($category->id);

        $this->getJson('/api/v1/products/'.$product->id.'/stock-attributes')
            ->assertOk()
            ->assertJsonPath('data.0.entity_type', 'estoque');

        $payload = [
            'stock_id' => $branch->stock->id,
            'stock_location_id' => $location->id,
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 1,
            'condition_id' => '',
            'unit_cost' => 0,
            'source' => 'fornecedor',
            'attributes' => [
                ['product_attribute_id' => $attribute->id, 'value' => 'Roxo'],
            ],
            'units' => [
                ['condition_id' => $condition->id, 'unit_cost' => 2000, 'attributes' => []],
            ],
        ];

        $this->postJson('/api/v1/stock-movements', $payload)->assertCreated();

        $item = StockItem::query()->where('stock_id', $branch->stock->id)->where('product_id', $product->id)->with('attributeValues', 'units')->firstOrFail();
        $this->assertSame(1, $item->quantity);
        $this->assertSame('Roxo', $item->attributeValues->firstWhere('product_attribute_id', $attribute->id)?->value);
        $this->assertSame('Roxo', $item->units->first()->attributes[$attribute->id] ?? null);
        $this->assertSame($location->id, $item->units->first()->stock_location_id);
        $this->assertSame('fornecedor', $item->units->first()->source);

        $this->getJson('/api/v1/stock-items?stock_id='.$branch->stock->id)
            ->assertOk()
            ->assertJsonPath('data.0.stock_location.name', 'Depósito')
            ->assertJsonPath('data.0.source', 'fornecedor')
            ->assertJsonPath('data.0.units.0.stock_location.name', 'Depósito')
            ->assertJsonPath('data.0.units.0.stock_location.code', 'DEP')
            ->assertJsonPath('data.0.units.0.source', 'fornecedor');
    }

    public function test_stock_items_index_returns_origin_and_location_per_unit_and_item(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial Shopping', 'code' => 'SHOP']);
        $product = Product::create(['sku' => 'CEL-SHOP-1', 'name' => 'Celular Pro', 'category' => 'Smartphones', 'sale_price' => 5000]);
        $location1 = StockLocation::create(['stock_id' => $branch->stock->id, 'name' => 'Prateleira A', 'code' => 'PRAT-A', 'type' => 'storage']);
        $location2 = StockLocation::create(['stock_id' => $branch->stock->id, 'name' => 'Vitrine B', 'code' => 'VIT-B', 'type' => 'display']);
        $condition = ProductCondition::create(['name' => 'Novo']);

        $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $branch->stock->id,
            'stock_location_id' => $location1->id,
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 1,
            'source' => 'fornecedor',
            'condition_id' => $condition->id,
            'unit_cost' => 3000,
        ])->assertCreated();

        $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $branch->stock->id,
            'stock_location_id' => $location2->id,
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 1,
            'source' => 'aparelho',
            'condition_id' => $condition->id,
            'unit_cost' => 2500,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/stock-items?stock_id='.$branch->stock->id)->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.quantity', 2);
        $response->assertJsonPath('data.0.units.0.stock_location.name', 'Prateleira A');
        $response->assertJsonPath('data.0.units.0.stock_location.code', 'PRAT-A');
        $response->assertJsonPath('data.0.units.0.source', 'fornecedor');
        $response->assertJsonPath('data.0.units.1.stock_location.name', 'Vitrine B');
        $response->assertJsonPath('data.0.units.1.stock_location.code', 'VIT-B');
        $response->assertJsonPath('data.0.units.1.source', 'aparelho');
    }
}
