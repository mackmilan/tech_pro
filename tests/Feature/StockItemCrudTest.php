<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCondition;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockItemCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_stock_movements_create_and_increment_separate_items_for_stock_controlling_attributes(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        $branch = Branch::create(['name' => 'Filial Variações', 'code' => 'VAR']);
        $product = Product::create([
            'sku' => 'CEL-STOCK-VARIANTS', 'name' => 'Celular', 'category' => 'Celulares',
            'category_id' => $category->id, 'cost_price' => 100, 'sale_price' => 150,
        ]);
        $color = ProductAttribute::create([
            'name' => 'Cor', 'entity_type' => 'produto', 'type' => 'selecao_multipla',
            'options' => ['Preto', 'Azul'], 'required' => true, 'differentiate_stock' => true,
        ]);
        $color->categories()->attach($category->id);
        $line = ProductAttribute::create([
            'name' => 'Linha', 'entity_type' => 'produto', 'type' => 'texto', 'differentiate_stock' => false,
        ]);
        $line->categories()->attach($category->id);
        $stockAttribute = ProductAttribute::create([
            'name' => 'Lote', 'entity_type' => 'estoque', 'type' => 'texto',
        ]);
        $location = StockLocation::create([
            'stock_id' => $branch->stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage',
        ]);
        $condition = ProductCondition::create(['name' => 'Novo']);

        $this->getJson('/api/v1/products/'.$product->id.'/stock-attributes')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['product_attribute_id' => $color->id, 'differentiate_stock' => true, 'type' => 'selecao_unica']);

        $basePayload = [
            'stock_id' => $branch->stock->id, 'stock_location_id' => $location->id, 'product_id' => $product->id,
            'type' => 'in', 'quantity' => 1, 'condition_id' => $condition->id, 'unit_cost' => 100,
        ];
        $this->postJson('/api/v1/stock-movements', [...$basePayload, 'attributes' => [
            ['product_attribute_id' => $color->id, 'value' => null],
            ['product_attribute_id' => $stockAttribute->id, 'value' => 'LOTE-1'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('attributes.0.value');
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $product->id]);
        $this->assertDatabaseMissing('stock_items', ['product_id' => $product->id]);
        $this->postJson('/api/v1/stock-movements', [...$basePayload, 'attributes' => [
            ['product_attribute_id' => $color->id, 'value' => ['Preto', 'Azul']],
            ['product_attribute_id' => $stockAttribute->id, 'value' => 'LOTE-1'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('attributes.0.value');
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $product->id]);
        $this->assertDatabaseMissing('stock_items', ['product_id' => $product->id]);

        foreach ([['Preto', 2], ['Azul', 3], ['Preto', 4]] as [$colorValue, $quantity]) {
            $this->postJson('/api/v1/stock-movements', [
                'stock_id' => $branch->stock->id, 'stock_location_id' => $location->id, 'product_id' => $product->id,
                'type' => 'in', 'quantity' => $quantity, 'condition_id' => $condition->id, 'unit_cost' => 100,
                'attributes' => [
                    ['product_attribute_id' => $color->id, 'value' => $colorValue],
                    ['product_attribute_id' => $stockAttribute->id, 'value' => 'LOTE-1'],
                ],
            ])->assertCreated();
        }

        $items = StockItem::query()->where('stock_id', $branch->stock->id)->where('product_id', $product->id)->get();
        $this->assertCount(2, $items);
        $this->assertSame([3, 6], $items->pluck('quantity')->sort()->values()->all());
        $this->assertDatabaseHas('stock_item_attribute_values', [
            'stock_item_id' => $items->firstWhere('quantity', 6)->id,
            'product_attribute_id' => $color->id,
            'value' => '"Preto"',
        ]);
        $this->assertDatabaseHas('stock_item_attribute_values', [
            'stock_item_id' => $items->firstWhere('quantity', 6)->id,
            'product_attribute_id' => $stockAttribute->id,
            'value' => '"LOTE-1"',
        ]);
        $this->assertDatabaseMissing('stock_item_attribute_values', [
            'stock_item_id' => $items->firstWhere('quantity', 3)->id,
            'product_attribute_id' => $color->id,
            'value' => '"Preto"',
        ]);
        $this->getJson('/api/v1/stock-items?per_page=100')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.quantity', 6)
            ->assertJsonPath('data.1.quantity', 3)
            ->assertJsonPath('data.0.attributes.0.name', 'Cor')
            ->assertJsonPath('data.0.attributes.0.value', 'Preto')
            ->assertJsonPath('data.0.attributes.0.differentiate_stock', true)
            ->assertJsonPath('data.0.attributes.1.name', 'Lote')
            ->assertJsonPath('data.0.attributes.1.value', 'LOTE-1')
            ->assertJsonPath('data.0.attributes.1.differentiate_stock', false);
        $this->getJson('/api/v1/stock-items/'.$items->firstWhere('quantity', 6)->id.'/attributes')->assertOk()
            ->assertJsonPath('data.0.value', 'Preto');
    }

    public function test_product_can_list_active_applicable_stock_attribute_definitions_for_a_new_entry(): void
    {
        $this->actingAs(User::factory()->create());
        $parent = Category::create(['name' => 'Celulares']);
        $category = Category::create(['name' => 'Smartphones', 'parent_id' => $parent->id]);
        $otherCategory = Category::create(['name' => 'Acessórios']);
        $product = Product::create([
            'sku' => 'CEL-STOCK-DEFS', 'name' => 'Celular com atributos', 'category' => 'Smartphones',
            'category_id' => $category->id, 'cost_price' => 100, 'sale_price' => 150,
        ]);
        $global = ProductAttribute::create(['name' => 'Lote', 'entity_type' => 'estoque', 'type' => 'texto', 'required' => true]);
        $parentAttribute = ProductAttribute::create(['name' => 'Cor', 'entity_type' => 'estoque', 'type' => 'selecao_unica', 'options' => ['Preto', 'Azul']]);
        $parentAttribute->categories()->attach($parent->id);
        $unrelated = ProductAttribute::create(['name' => 'Tamanho', 'entity_type' => 'estoque', 'type' => 'texto']);
        $unrelated->categories()->attach($otherCategory->id);
        ProductAttribute::create(['name' => 'Desativado', 'entity_type' => 'estoque', 'type' => 'texto', 'active' => false]);
        ProductAttribute::create(['name' => 'Só produto', 'entity_type' => 'produto', 'type' => 'texto']);

        $this->getJson('/api/v1/products/'.$product->id.'/stock-attributes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.product_attribute_id', $parentAttribute->id)
            ->assertJsonPath('data.0.options', ['Preto', 'Azul'])
            ->assertJsonPath('data.1.product_attribute_id', $global->id)
            ->assertJsonMissing(['product_attribute_id' => $unrelated->id]);
    }

    public function test_new_entry_can_fetch_applicable_stock_attribute_definitions(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        $product = Product::create([
            'sku' => 'STOCK-ENTRY-ATTRS', 'name' => 'Celular', 'category' => 'Celulares',
            'category_id' => $category->id, 'cost_price' => 100, 'sale_price' => 150,
        ]);
        $attribute = ProductAttribute::create([
            'name' => 'Recursos', 'entity_type' => 'estoque', 'type' => 'selecao_multipla',
            'options' => ['5G', 'NFC'], 'required' => true,
        ]);
        $attribute->categories()->attach($category->id);

        $this->getJson('/api/v1/products/'.$product->id.'/stock-attributes')
            ->assertOk()
            ->assertJsonPath('data.0.product_attribute_id', $attribute->id)
            ->assertJsonPath('data.0.type', 'selecao_multipla')
            ->assertJsonPath('data.0.options', ['5G', 'NFC'])
            ->assertJsonPath('data.0.required', true);
    }

    public function test_inbound_stock_movement_saves_attribute_values_on_the_created_stock_item(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial atributos entrada', 'code' => 'EATTR']);
        $product = Product::create([
            'sku' => 'STOCK-ENTRY-ATTR-VALUES', 'name' => 'Celular', 'category' => 'Celulares',
            'cost_price' => 100, 'sale_price' => 150,
        ]);
        $attribute = ProductAttribute::create([
            'name' => 'Recursos', 'entity_type' => 'estoque', 'type' => 'selecao_multipla',
            'options' => ['5G', 'NFC'], 'required' => true,
        ]);
        $location = StockLocation::create([
            'stock_id' => $branch->stock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage',
        ]);
        $condition = ProductCondition::create(['name' => 'Novo']);

        $movement = $this->postJson('/api/v1/stock-movements', [
            'stock_id' => $branch->stock->id, 'stock_location_id' => $location->id, 'product_id' => $product->id,
            'type' => 'in', 'quantity' => 2, 'condition_id' => $condition->id, 'unit_cost' => 100,
            'attributes' => [['product_attribute_id' => $attribute->id, 'value' => ['5G', 'NFC']]],
        ])->assertCreated()->json();

        $item = StockItem::query()->where('stock_id', $branch->stock->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertDatabaseHas('stock_item_attribute_values', [
            'stock_item_id' => $item->id,
            'product_attribute_id' => $attribute->id,
            'value' => '["5G","NFC"]',
        ]);
        $this->getJson('/api/v1/stock-items/'.$item->id.'/attributes')->assertOk()->assertJsonPath('data.0.value', ['5G', 'NFC']);
    }

    public function test_product_can_list_its_attribute_definitions_for_display(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        $product = Product::create([
            'sku' => 'CEL-PROD-ATTR', 'name' => 'Celular atributo', 'category' => 'Celulares',
            'category_id' => $category->id, 'cost_price' => 100, 'sale_price' => 150,
        ]);
        $productAttribute = ProductAttribute::create([
            'name' => 'Linha', 'entity_type' => 'produto', 'type' => 'texto', 'options' => null,
            'required' => false, 'differentiate_stock' => false,
        ]);
        $productAttribute->categories()->attach($category->id);
        ProductAttribute::create([
            'name' => 'Material interno', 'entity_type' => 'estoque', 'type' => 'texto', 'options' => null,
            'required' => false, 'differentiate_stock' => false,
        ]);

        $this->getJson('/api/v1/products/'.$product->id.'/attributes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_attribute_id', $productAttribute->id)
            ->assertJsonPath('data.0.name', 'Linha')
            ->assertJsonMissing(['name' => 'Material interno']);
    }

    public function test_product_attribute_values_are_persisted_per_product_and_return_only_product_definitions(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        $product = Product::create([
            'sku' => 'CEL-PROD-ATTR-VALUE', 'name' => 'Celular atributo', 'category' => 'Celulares',
            'category_id' => $category->id, 'cost_price' => 100, 'sale_price' => 150,
        ]);
        $productAttribute = ProductAttribute::create([
            'name' => 'Linha', 'entity_type' => 'produto', 'type' => 'texto', 'options' => null,
            'required' => true, 'differentiate_stock' => false,
        ]);
        $productAttribute->categories()->attach($category->id);
        $stockAttribute = ProductAttribute::create([
            'name' => 'Lote', 'entity_type' => 'estoque', 'type' => 'texto', 'options' => null,
            'required' => false, 'differentiate_stock' => false,
        ]);
        $url = '/api/v1/products/'.$product->id.'/attributes';

        $this->getJson($url)->assertOk()->assertJsonPath('data.0.value', null);
        $this->putJson($url, ['attributes' => [
            ['product_attribute_id' => $productAttribute->id, 'value' => 'Série X'],
        ]])->assertOk()->assertJsonPath('data.0.value', 'Série X');
        $this->getJson($url)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', 'Série X')
            ->assertJsonMissing(['product_attribute_id' => $stockAttribute->id]);
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_attribute_id' => $productAttribute->id,
            'value' => '"Série X"',
        ]);
        $stockItem = StockItem::create([
            'stock_id' => Branch::create(['name' => 'Filial Atributo Produto', 'code' => 'PATTR'])->stock->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        $this->putJson('/api/v1/stock-items/'.$stockItem->id.'/attributes', ['attributes' => [
            ['product_attribute_id' => $stockAttribute->id, 'value' => 'Lote 1'],
        ]])->assertOk()->assertJsonPath('data.0.value', 'Lote 1');
        $this->getJson('/api/v1/stock-items/'.$stockItem->id.'/attributes')
            ->assertOk()
            ->assertJsonMissing(['product_attribute_id' => $productAttribute->id])
            ->assertJsonPath('data.0.value', 'Lote 1');
    }

    public function test_stock_item_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/stock-items')->assertUnauthorized();
    }

    public function test_authenticated_user_can_list_stock_items_with_product_and_branch(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial Centro', 'code' => 'CENTRO']);
        $product = Product::create([
            'sku' => 'CEL-1',
            'name' => 'Celular A',
            'category' => 'Celulares',
            'cost_price' => 100,
            'sale_price' => 150,
        ]);
        $item = StockItem::create([
            'stock_id' => $branch->stock->id,
            'product_id' => $product->id,
            'quantity' => 7,
        ]);
        $attribute = ProductAttribute::create(['name' => 'Cor', 'entity_type' => 'estoque', 'type' => 'texto']);
        $item->attributeValues()->create(['product_attribute_id' => $attribute->id, 'value' => 'Azul']);

        $this->getJson('/api/v1/stock-items')
            ->assertOk()
            ->assertJsonPath('data.0.id', $item->id)
            ->assertJsonPath('data.0.quantity', 7)
            ->assertJsonPath('data.0.product.name', 'Celular A')
            ->assertJsonPath('data.0.product.sku', 'CEL-1')
            ->assertJsonPath('data.0.stock.branch.name', 'Filial Centro')
            ->assertJsonPath('data.0.stock.branch.code', 'CENTRO')
            ->assertJsonPath('data.0.attributes.0.name', 'Cor')
            ->assertJsonPath('data.0.attributes.0.value', 'Azul');
    }

    public function test_stock_item_attributes_reuse_product_definitions_and_store_values_per_item(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        $product = Product::create([
            'sku' => 'CEL-ATTR-1',
            'name' => 'Celular com atributos',
            'category' => 'Celulares',
            'category_id' => $category->id,
            'cost_price' => 100,
            'sale_price' => 150,
        ]);
        $storageAttribute = ProductAttribute::create([
            'name' => 'Armazenamento',
            'entity_type' => 'estoque',
            'type' => 'selecao_unica',
            'options' => ['128 GB', '256 GB'],
            'required' => true,
            'differentiate_stock' => true,
        ]);
        $storageAttribute->categories()->attach($category->id);
        $colorAttribute = ProductAttribute::create([
            'name' => 'Cor',
            'entity_type' => 'estoque',
            'type' => 'texto',
            'options' => null,
            'required' => false,
            'differentiate_stock' => false,
        ]);
        $productOnlyAttribute = ProductAttribute::create([
            'name' => 'Linha do produto',
            'entity_type' => 'produto',
            'type' => 'texto',
            'options' => null,
            'required' => false,
            'differentiate_stock' => false,
        ]);
        $productOnlyAttribute->categories()->attach($category->id);
        $otherCategory = Category::create(['name' => 'Tablets']);
        $unavailableAttribute = \App\Models\ProductAttribute::create([
            'name' => 'Tamanho',
            'entity_type' => 'estoque',
            'type' => 'texto',
            'options' => null,
            'required' => false,
            'differentiate_stock' => false,
        ]);
        $unavailableAttribute->categories()->attach($otherCategory->id);
        $stockItem = StockItem::create([
            'stock_id' => Branch::create(['name' => 'Filial Atributos', 'code' => 'ATTR'])->stock->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->getJson('/api/v1/stock-items/'.$stockItem->id.'/attributes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.product_attribute_id', $storageAttribute->id)
            ->assertJsonPath('data.0.name', 'Armazenamento')
            ->assertJsonPath('data.0.type', 'selecao_unica')
            ->assertJsonPath('data.0.value', null)
            ->assertJsonPath('data.1.product_attribute_id', $colorAttribute->id)
            ->assertJsonMissing(['name' => 'Tamanho'])
            ->assertJsonMissing(['product_attribute_id' => $productOnlyAttribute->id]);

        $this->putJson('/api/v1/stock-items/'.$stockItem->id.'/attributes', [
            'attributes' => [
                ['product_attribute_id' => $storageAttribute->id, 'value' => '256 GB'],
                ['product_attribute_id' => $colorAttribute->id, 'value' => 'Azul'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.0.value', '256 GB')
            ->assertJsonPath('data.1.value', 'Azul');

        $this->assertDatabaseHas('stock_item_attribute_values', [
            'stock_item_id' => $stockItem->id,
            'product_attribute_id' => $storageAttribute->id,
            'value' => '"256 GB"',
        ]);
        $this->getJson('/api/v1/stock-items/'.$stockItem->id.'/attributes')
            ->assertOk()
            ->assertJsonPath('data.0.value', '256 GB')
            ->assertJsonPath('data.1.value', 'Azul');
    }

    public function test_stock_item_attributes_are_independent_between_items_and_removed_definitions_are_ignored(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::create([
            'sku' => 'CEL-ATTR-INDEPENDENT', 'name' => 'Celular', 'category' => 'Celulares',
            'cost_price' => 100, 'sale_price' => 150,
        ]);
        $attribute = ProductAttribute::create([
            'name' => 'Cor',
            'entity_type' => 'estoque',
            'type' => 'texto', 'options' => null,
            'required' => false, 'differentiate_stock' => false,
        ]);
        $stock = Branch::create(['name' => 'Filial Independência', 'code' => 'IND'])->stock;
        $first = StockItem::create(['stock_id' => $stock->id, 'product_id' => $product->id, 'quantity' => 1]);
        $second = StockItem::create(['stock_id' => Branch::create(['name' => 'Filial Independência 2', 'code' => 'IND2'])->stock->id, 'product_id' => $product->id, 'quantity' => 2]);
        $url = '/api/v1/stock-items/'.$first->id.'/attributes';

        $this->putJson($url, ['attributes' => [
            ['product_attribute_id' => $attribute->id, 'value' => 'Azul'],
        ]])->assertOk()->assertJsonPath('data.0.value', 'Azul');
        $this->putJson('/api/v1/stock-items/'.$second->id.'/attributes', ['attributes' => [
            ['product_attribute_id' => $attribute->id, 'value' => 'Vermelho'],
        ]])->assertOk()->assertJsonPath('data.0.value', 'Vermelho');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.value', 'Azul');

        $attribute->update(['active' => false]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('stock_item_attribute_values', [
            'stock_item_id' => $first->id,
            'product_attribute_id' => $attribute->id,
            'value' => '"Azul"',
        ]);
    }

    public function test_stock_item_attributes_require_required_values_and_valid_choices(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::create([
            'sku' => 'CEL-ATTR-2',
            'name' => 'Celular validado',
            'category' => 'Celulares',
            'cost_price' => 100,
            'sale_price' => 150,
        ]);
        $attribute = \App\Models\ProductAttribute::create([
            'name' => 'Cor',
            'entity_type' => 'estoque',
            'type' => 'selecao_unica',
            'options' => ['Preto', 'Branco'],
            'required' => true,
            'differentiate_stock' => true,
        ]);
        $stockItem = StockItem::create([
            'stock_id' => Branch::create(['name' => 'Filial Validação', 'code' => 'VAL'])->stock->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        $url = '/api/v1/stock-items/'.$stockItem->id.'/attributes';

        $this->putJson($url, ['attributes' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attributes');
        $this->putJson($url, ['attributes' => [
            ['product_attribute_id' => $attribute->id, 'value' => 'Verde'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('attributes.0.value');
    }

    public function test_guests_cannot_read_or_update_stock_item_attributes(): void
    {
        $stock = Branch::create(['name' => 'Filial Restrita', 'code' => 'REST'])->stock;
        $product = Product::create([
            'sku' => 'CEL-ATTR-3', 'name' => 'Celular restrito', 'category' => 'Celulares',
            'cost_price' => 100, 'sale_price' => 150,
        ]);
        $stockItem = StockItem::create(['stock_id' => $stock->id, 'product_id' => $product->id, 'quantity' => 1]);
        $url = '/api/v1/stock-items/'.$stockItem->id.'/attributes';

        $this->getJson($url)->assertUnauthorized();
        $this->putJson($url, ['attributes' => []])->assertUnauthorized();
    }

    public function test_stock_items_can_be_filtered_by_stock(): void
    {
        $this->actingAs(User::factory()->create());
        $firstStock = Branch::create(['name' => 'Filial A', 'code' => 'A'])->stock;
        $secondStock = Branch::create(['name' => 'Filial B', 'code' => 'B'])->stock;
        $product = Product::create([
            'sku' => 'CEL-2',
            'name' => 'Celular B',
            'category' => 'Celulares',
            'cost_price' => 100,
            'sale_price' => 150,
        ]);
        $expectedItem = StockItem::create(['stock_id' => $firstStock->id, 'product_id' => $product->id, 'quantity' => 3]);
        StockItem::create(['stock_id' => $secondStock->id, 'product_id' => $product->id, 'quantity' => 9]);

        $this->getJson('/api/v1/stock-items?stock_id='.$firstStock->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expectedItem->id);
    }
}
