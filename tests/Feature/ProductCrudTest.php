<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_entity_no_longer_has_cost_price_or_condition_id_columns(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('products', 'cost_price'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('products', 'condition_id'));

        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/products', [
            'sku' => 'PRODUCT-WITHOUT-LEGACY-FIELDS', 'name' => 'Telefone', 'category' => 'Celulares',
            'sale_price' => 150, 'cost_price' => 100, 'condition_id' => 'ignored-legacy-value',
        ])->assertCreated()->assertJsonMissingPath('cost_price')->assertJsonMissingPath('condition_id');
    }

    public function test_guest_cannot_access_product_crud_api(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_delete_products(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        $brand = Brand::create(['name' => 'Marca móvel']);
        $payload = [
            'sku' => 'CRUD-PRODUCT-001', 'name' => 'Telefone Alpha', 'category' => 'Celulares',
            'category_id' => $category->id, 'brand' => $brand->name, 'brand_id' => $brand->id,
            'description' => 'Modelo de teste', 'sale_price' => 159.90, 'active' => true,
        ];
        $product = $this->postJson('/api/v1/products', $payload)->assertCreated()
            ->assertJsonPath('sku', $payload['sku'])->assertJsonPath('category_id', $category->id)
            ->assertJsonPath('brand_id', $brand->id)
            ->assertJsonPath('category_relation.id', $category->id)->assertJsonPath('brand_relation.id', $brand->id)
            ->assertJsonMissingPath('cost_price')->assertJsonMissingPath('condition_id')->json();

        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.id', $product['id'])
            ->assertJsonPath('data.0.category_relation.id', $category->id)
            ->assertJsonPath('data.0.brand_relation.id', $brand->id)
            ->assertJsonMissingPath('data.0.cost_price')->assertJsonMissingPath('data.0.condition_id');
        $this->getJson('/api/v1/products/'.$product['id'])->assertOk()->assertJsonPath('id', $product['id'])
            ->assertJsonMissingPath('cost_price')->assertJsonMissingPath('condition_id')
            ->assertJsonPath('category_relation.id', $category->id);
        $this->putJson('/api/v1/products/'.$product['id'], [...$payload, 'name' => 'Telefone Beta', 'sale_price' => 179.90])
            ->assertOk()->assertJsonPath('name', 'Telefone Beta')->assertJsonPath('sale_price', '179.90')
            ->assertJsonPath('brand_relation.id', $brand->id);
        $this->deleteJson('/api/v1/products/'.$product['id'])->assertNoContent();
        $this->assertSoftDeleted('products', ['id' => $product['id']]);
        $this->getJson('/api/v1/products/'.$product['id'])->assertNotFound();
    }

    public function test_authenticated_user_can_filter_products_and_search_by_name_or_sku(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        $first = Product::create(['sku' => 'PHONE-SEARCH-1', 'name' => 'Telefone Especial', 'category' => 'Celulares', 'category_id' => $category->id, 'sale_price' => 20, 'active' => true]);
        Product::create(['sku' => 'OTHER-SEARCH', 'name' => 'Outro item', 'category' => 'Celulares', 'category_id' => $category->id, 'sale_price' => 20, 'active' => false]);

        $this->getJson('/api/v1/products?search=PHONE-SEARCH-1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/products?active=1&category_id='.$category->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
    }

    public function test_product_crud_validates_required_fields_and_foreign_keys(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);
        Brand::create(['name' => 'Marca válida']);
        $this->postJson('/api/v1/products', [])->assertUnprocessable()->assertJsonValidationErrors(['sku', 'name', 'category', 'sale_price']);
        $this->postJson('/api/v1/products', [
            'sku' => 'INVALID-RELATION', 'name' => 'Telefone', 'category' => 'Celulares',
            'category_id' => 'not-a-uuid', 'brand_id' => 'not-a-uuid',
            'sale_price' => 'abc',
        ])->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'brand_id', 'sale_price']);
    }

    public function test_product_form_attribute_contract_excludes_stock_definitions_and_round_trips_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $category = Category::create(['name' => 'Celulares']);
        $product = Product::create(['sku' => 'PRODUCT-FORM-ATTR', 'name' => 'Celular', 'category' => 'Celulares', 'category_id' => $category->id, 'sale_price' => 20]);
        $productAttribute = ProductAttribute::create(['name' => 'Cor', 'entity_type' => 'produto', 'type' => 'selecao_unica', 'options' => ['Preto', 'Azul'], 'required' => true, 'differentiate_stock' => false]);
        $productAttribute->categories()->attach($category->id);
        $stockAttribute = ProductAttribute::create(['name' => 'Lote', 'entity_type' => 'estoque', 'type' => 'texto', 'options' => null, 'required' => false, 'differentiate_stock' => false]);

        $definitions = $this->getJson('/api/v1/product-attributes?entity_type=produto&all=true&category_id='.$category->id)
            ->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame($productAttribute->id, $definitions[0]['id']);
        $this->assertSame(['Preto', 'Azul'], $definitions[0]['options']);
        $this->getJson('/api/v1/products/'.$product->id.'/attributes')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_attribute_id', $productAttribute->id)->assertJsonPath('data.0.value', null)
            ->assertJsonMissing(['product_attribute_id' => $stockAttribute->id]);
        $this->putJson('/api/v1/products/'.$product->id.'/attributes', ['attributes' => [['product_attribute_id' => $productAttribute->id, 'value' => 'Azul']]])
            ->assertOk()->assertJsonPath('data.0.value', 'Azul');
        $this->assertDatabaseHas('product_attribute_values', ['product_id' => $product->id, 'product_attribute_id' => $productAttribute->id, 'value' => '"Azul"']);
        $this->getJson('/api/v1/products/'.$product->id.'/attributes')->assertOk()->assertJsonPath('data.0.value', 'Azul');
    }
}
