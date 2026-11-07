<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class CategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_category_crud_api(): void
    {
        $this->getJson('/api/v1/categories')->assertUnauthorized();
    }

    public function test_authenticated_web_session_can_access_category_api(): void
    {
        $user = User::factory()->create();
        Auth::login($user);
        $this->withSession([]);
        $request = Request::create('/api/v1/categories', 'GET', server: [
            'HTTP_REFERER' => 'http://localhost:8000/erp/categorias',
        ]);

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
        $this->getJson('/api/v1/categories', ['Referer' => 'http://localhost:8000/erp/categorias'])
            ->assertOk();
    }

    public function test_categories_can_be_created_and_listed_as_a_nested_tree(): void
    {
        $user = User::factory()->create();

        $parent = $this->actingAs($user)->postJson('/api/v1/categories', [
            'name' => 'Eletrônicos',
        ])->assertCreated()->assertJsonPath('parent_id', null)->json();

        $this->postJson('/api/v1/categories', [
            'name' => 'Celulares',
            'parent_id' => $parent['id'],
        ])->assertCreated()->assertJsonPath('parent_id', $parent['id']);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Eletrônicos')
            ->assertJsonPath('data.0.children.0.name', 'Celulares');

        $this->postJson('/api/v1/categories', [
            'name' => 'Smartphones',
            'parent_id' => 'not-a-uuid',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/products', [
            'sku' => 'TEST-CATEGORY-1',
            'name' => 'Smartphone',
            'category' => 'Celulares',
            'category_id' => $parent['id'],
            'cost_price' => 10,
            'sale_price' => 20,
        ])->assertCreated()->assertJsonPath('category_id', $parent['id']);
    }

    public function test_category_can_be_updated_and_deleted_when_it_has_no_children(): void
    {
        $user = User::factory()->create();
        $category = $this->actingAs($user)->postJson('/api/v1/categories', ['name' => 'Acessórios'])
            ->assertCreated()->json();

        $this->putJson('/api/v1/categories/'.$category['id'], [
            'name' => 'Acessórios premium',
            'parent_id' => null,
        ])->assertOk()->assertJsonPath('name', 'Acessórios premium');

        $this->deleteJson('/api/v1/categories/'.$category['id'])->assertNoContent();
        $this->assertSoftDeleted('categories', ['id' => $category['id']]);
    }

    public function test_category_cannot_be_deleted_while_it_has_children(): void
    {
        $user = User::factory()->create();
        $parent = $this->actingAs($user)->postJson('/api/v1/categories', ['name' => 'Eletrônicos'])
            ->assertCreated()->json();
        $this->postJson('/api/v1/categories', ['name' => 'Celulares', 'parent_id' => $parent['id']])->assertCreated();

        $this->deleteJson('/api/v1/categories/'.$parent['id'])->assertUnprocessable();
        $this->assertDatabaseHas('categories', ['id' => $parent['id']]);
    }

    public function test_category_cannot_be_moved_under_itself_or_its_descendant(): void
    {
        $user = User::factory()->create();
        $root = $this->actingAs($user)->postJson('/api/v1/categories', ['name' => 'Eletrônicos'])
            ->assertCreated()->json();
        $child = $this->postJson('/api/v1/categories', ['name' => 'Celulares', 'parent_id' => $root['id']])
            ->assertCreated()->json();

        $this->putJson('/api/v1/categories/'.$root['id'], [
            'name' => 'Eletrônicos',
            'parent_id' => $root['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->putJson('/api/v1/categories/'.$root['id'], [
            'name' => 'Eletrônicos',
            'parent_id' => $child['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->putJson('/api/v1/categories/'.$child['id'], [
            'name' => 'Celulares',
            'parent_id' => null,
        ])->assertOk();

        $this->getJson('/api/v1/categories')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.parent_id', null);
    }
}
