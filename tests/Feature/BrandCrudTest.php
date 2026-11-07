<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_brand_crud_api(): void
    {
        $this->getJson('/api/v1/brands')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_delete_brands(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $brand = $this->postJson('/api/v1/brands', ['name' => 'Samsung'])
            ->assertCreated()
            ->assertJsonPath('name', 'Samsung')
            ->json();

        $this->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonPath('data.0.id', $brand['id'])
            ->assertJsonPath('data.0.name', 'Samsung');
        $this->getJson('/api/v1/brands/'.$brand['id'])->assertOk()->assertJsonPath('id', $brand['id']);
        $this->putJson('/api/v1/brands/'.$brand['id'], ['name' => 'Samsung Electronics', 'active' => false])
            ->assertOk()
            ->assertJsonPath('name', 'Samsung Electronics')
            ->assertJsonPath('active', false);
        $this->deleteJson('/api/v1/brands/'.$brand['id'])->assertNoContent();
        $this->assertSoftDeleted('brands', ['id' => $brand['id']]);
        $this->getJson('/api/v1/brands/'.$brand['id'])->assertNotFound();
    }

    public function test_brand_name_is_required_and_unique(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/brands', ['name' => 'Apple'])->assertCreated();
        $this->postJson('/api/v1/brands', ['name' => 'Apple'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/brands', [])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_brand_name_may_be_reused_after_soft_delete(): void
    {
        $this->actingAs(User::factory()->create());
        $brand = $this->postJson('/api/v1/brands', ['name' => 'Nokia'])->assertCreated()->json();
        $this->deleteJson('/api/v1/brands/'.$brand['id'])->assertNoContent();

        $this->postJson('/api/v1/brands', ['name' => 'Nokia'])->assertCreated()->assertJsonPath('name', 'Nokia');
    }

    public function test_brand_cannot_be_deleted_while_a_product_uses_it(): void
    {
        $this->actingAs(User::factory()->create());
        $brand = $this->postJson('/api/v1/brands', ['name' => 'Motorola'])->assertCreated()->json();
        $this->postJson('/api/v1/products', [
            'sku' => 'BRAND-LINK-1',
            'name' => 'Moto G',
            'category' => 'Celulares',
            'brand' => 'Motorola',
            'brand_id' => $brand['id'],
            'cost_price' => 10,
            'sale_price' => 20,
        ])->assertCreated()->assertJsonPath('brand_id', $brand['id']);
        $this->assertDatabaseHas('products', ['brand' => 'Motorola', 'brand_id' => $brand['id']]);

        $this->deleteJson('/api/v1/brands/'.$brand['id'])->assertUnprocessable()->assertJsonValidationErrors('brand');
        $this->assertDatabaseHas('brands', ['id' => $brand['id'], 'deleted_at' => null]);
    }
}
