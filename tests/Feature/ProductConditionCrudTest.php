<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductConditionCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_product_condition_api(): void
    {
        $this->getJson('/api/v1/product-conditions')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_delete_product_conditions(): void
    {
        $this->actingAs(User::factory()->create());

        $condition = $this->postJson('/api/v1/product-conditions', ['name' => 'Novo'])
            ->assertCreated()
            ->assertJsonPath('name', 'Novo')
            ->assertJsonPath('active', true)
            ->json();

        $this->postJson('/api/v1/product-conditions', ['name' => 'Usado'])->assertCreated();
        $this->getJson('/api/v1/product-conditions')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Novo')
            ->assertJsonPath('data.1.name', 'Usado');
        $this->getJson('/api/v1/product-conditions/'.$condition['id'])
            ->assertOk()
            ->assertJsonPath('id', $condition['id']);
        $this->putJson('/api/v1/product-conditions/'.$condition['id'], ['name' => 'Novo lacrado', 'active' => false])
            ->assertOk()
            ->assertJsonPath('name', 'Novo lacrado')
            ->assertJsonPath('active', false);
        $this->deleteJson('/api/v1/product-conditions/'.$condition['id'])->assertNoContent();
        $this->assertSoftDeleted('product_conditions', ['id' => $condition['id']]);
    }

    public function test_product_condition_name_is_required_and_unique_and_can_be_reused_after_soft_delete(): void
    {
        $this->actingAs(User::factory()->create());
        $condition = $this->postJson('/api/v1/product-conditions', ['name' => 'Recondicionado'])->assertCreated()->json();
        $this->postJson('/api/v1/product-conditions', ['name' => 'Recondicionado'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/product-conditions', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->deleteJson('/api/v1/product-conditions/'.$condition['id'])->assertNoContent();
        $this->postJson('/api/v1/product-conditions', ['name' => 'Recondicionado'])->assertCreated();
    }

    public function test_product_condition_can_be_deleted_independently_of_products(): void
    {
        $this->actingAs(User::factory()->create());
        $condition = $this->postJson('/api/v1/product-conditions', ['name' => 'Usado'])->assertCreated()->json();
        $this->postJson('/api/v1/products', [
            'sku' => 'CONDITION-1',
            'name' => 'iPhone usado',
            'category' => 'Celulares',
            'sale_price' => 20,
        ])->assertCreated();

        $this->deleteJson('/api/v1/product-conditions/'.$condition['id'])->assertNoContent();
        $this->assertSoftDeleted('product_conditions', ['id' => $condition['id']]);
    }
}
