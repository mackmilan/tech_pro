<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAttributeCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_product_attribute_api(): void
    {
        $this->getJson('/api/v1/product-attributes')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_delete_attributes_with_categories(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Celulares']);

        $attribute = $this->postJson('/api/v1/product-attributes', [
            'name' => 'Armazenamento',
            'entity_type' => 'produto',
            'type' => 'selecao_unica',
            'options' => ['128 GB', '256 GB'],
            'required' => true,
            'differentiate_stock' => true,
            'category_ids' => [$category->id],
        ])->assertCreated()
            ->assertJsonPath('name', 'Armazenamento')
            ->assertJsonPath('entity_type', 'produto')
            ->assertJsonPath('type', 'selecao_unica')
            ->assertJsonPath('options.1', '256 GB')
            ->assertJsonPath('required', true)
            ->assertJsonPath('differentiate_stock', true)
            ->assertJsonPath('categories.0.id', $category->id)
            ->json();

        $this->getJson('/api/v1/product-attributes')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $attribute['id'])
            ->assertJsonPath('data.data.0.categories.0.name', 'Celulares');
        $this->getJson('/api/v1/product-attributes/'.$attribute['id'])
            ->assertOk()
            ->assertJsonPath('options.0', '128 GB');

        $otherCategory = Category::create(['name' => 'Tablets']);
        $this->putJson('/api/v1/product-attributes/'.$attribute['id'], [
            'entity_type' => 'produto',
            'name' => 'Memória',
            'type' => 'numerico',
            'options' => [],
            'required' => false,
            'differentiate_stock' => false,
            'category_ids' => [$otherCategory->id],
        ])->assertOk()
            ->assertJsonPath('name', 'Memória')
            ->assertJsonPath('type', 'numerico')
            ->assertJsonPath('options', null)
            ->assertJsonPath('categories.0.id', $otherCategory->id);

        $this->deleteJson('/api/v1/product-attributes/'.$attribute['id'])->assertNoContent();
        $this->assertSoftDeleted('product_attributes', ['id' => $attribute['id']]);
    }

    public function test_selection_attributes_require_choices_and_non_selection_attributes_reject_them(): void
    {
        $this->actingAs(User::factory()->create());
        $base = [
            'entity_type' => 'produto',
            'name' => 'Cor', 'required' => false, 'differentiate_stock' => false, 'category_ids' => [],
        ];

        $this->postJson('/api/v1/product-attributes', [...$base, 'type' => 'selecao_unica', 'options' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('options');
        $this->postJson('/api/v1/product-attributes', [...$base, 'type' => 'selecao_multipla', 'options' => ['Azul']])
            ->assertCreated();
        $this->postJson('/api/v1/product-attributes', [...$base, 'type' => 'texto', 'options' => ['Azul']])
            ->assertUnprocessable()->assertJsonValidationErrors('options');
        $this->postJson('/api/v1/product-attributes', [...$base, 'type' => 'invalido', 'options' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_product_attribute_index_can_filter_by_category_ancestry_for_product_editors(): void
    {
        $this->actingAs(User::factory()->create());
        $root = Category::create(['name' => 'Eletrônicos']);
        $child = Category::create(['name' => 'Celulares', 'parent_id' => $root->id]);
        $unrelated = Category::create(['name' => 'Móveis']);
        $all = ProductAttribute::create(['name' => 'Garantia universal', 'entity_type' => 'produto', 'type' => 'texto']);
        $onRoot = ProductAttribute::create(['name' => 'Conexão', 'entity_type' => 'produto', 'type' => 'texto']);
        $onRoot->categories()->attach($root->id);
        $onOther = ProductAttribute::create(['name' => 'Material móvel', 'entity_type' => 'produto', 'type' => 'texto']);
        $onOther->categories()->attach($unrelated->id);
        ProductAttribute::create(['name' => 'Lote estoque', 'entity_type' => 'estoque', 'type' => 'texto']);

        $this->getJson('/api/v1/product-attributes?entity_type=produto&all=true&category_id='.$child->id)
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Conexão')
            ->assertJsonPath('data.1.name', 'Garantia universal')
            ->assertJsonMissing(['id' => $onOther->id]);
    }

    public function test_stock_entity_attributes_can_be_created_and_listed_separately_from_product_attributes(): void
    {
        $this->actingAs(User::factory()->create());
        $base = [
            'type' => 'texto', 'options' => [], 'required' => false,
            'differentiate_stock' => false, 'category_ids' => [],
        ];

        $category = Category::create(['name' => 'Celulares']);
        $stockAttribute = $this->postJson('/api/v1/product-attributes', [...$base, 'name' => 'Material', 'entity_type' => 'estoque', 'category_ids' => [$category->id]])
            ->assertCreated()
            ->assertJsonPath('entity_type', 'estoque')
            ->assertJsonPath('categories.0.id', $category->id)
            ->json();
        $this->getJson('/api/v1/product-attributes?entity_type=estoque&all=true&category_id='.$category->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $stockAttribute['id']);
        $this->postJson('/api/v1/product-attributes', [...$base, 'name' => 'Garantia', 'entity_type' => 'produto'])
            ->assertCreated()
            ->assertJsonPath('entity_type', 'produto');

        $this->getJson('/api/v1/product-attributes?entity_type=estoque')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.name', 'Material');
        $this->getJson('/api/v1/product-attributes?entity_type=produto')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.name', 'Garantia');
        $this->getJson('/api/v1/product-attributes?entity_type=desconhecido')
            ->assertUnprocessable()->assertJsonValidationErrors('entity_type');
    }

    public function test_attribute_rejects_unknown_entity_type_and_missing_entity_type(): void
    {
        $this->actingAs(User::factory()->create());
        $base = [
            'name' => 'Acabamento', 'type' => 'texto', 'options' => [], 'required' => false,
            'differentiate_stock' => false, 'category_ids' => [],
        ];

        $this->postJson('/api/v1/product-attributes', [...$base, 'entity_type' => 'filial'])
            ->assertUnprocessable()->assertJsonValidationErrors('entity_type');
        $this->postJson('/api/v1/product-attributes', $base)
            ->assertUnprocessable()->assertJsonValidationErrors('entity_type');
    }

    public function test_attribute_rejects_unknown_category_ids_and_missing_flags(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/product-attributes', [
            'name' => 'Voltagem',
            'type' => 'booleano',
            'category_ids' => ['00000000-0000-4000-8000-000000000000'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['entity_type', 'required', 'category_ids.0']);
    }
}
