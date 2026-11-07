<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLocationCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_stock_location_crud(): void
    {
        $this->getJson('/api/v1/stock-locations')->assertUnauthorized();
        $this->postJson('/api/v1/stock-locations', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_soft_delete_stock_locations(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial Centro', 'code' => 'CENTRO']);

        $location = $this->postJson('/api/v1/stock-locations', [
            'stock_id' => $branch->stock->id,
            'name' => 'Depósito principal',
            'code' => 'DEP-01',
            'type' => 'storage',
        ])->assertCreated()
            ->assertJsonPath('stock_id', $branch->stock->id)
            ->assertJsonPath('name', 'Depósito principal')
            ->json();

        $this->getJson('/api/v1/stock-locations?stock_id='.$branch->stock->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $location['id'])
            ->assertJsonPath('data.0.stock.branch.name', 'Filial Centro');

        $this->getJson('/api/v1/stock-locations/'.$location['id'])
            ->assertOk()
            ->assertJsonPath('stock.branch.code', 'CENTRO');

        $this->putJson('/api/v1/stock-locations/'.$location['id'], [
            'stock_id' => $branch->stock->id,
            'name' => 'Depósito atualizado',
            'code' => 'DEP-01',
            'type' => 'shelf',
        ])->assertOk()
            ->assertJsonPath('name', 'Depósito atualizado')
            ->assertJsonPath('type', 'shelf');

        $this->deleteJson('/api/v1/stock-locations/'.$location['id'])->assertNoContent();
        $this->assertSoftDeleted('stock_locations', ['id' => $location['id']]);
        $this->getJson('/api/v1/stock-locations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_location_crud_validates_stock_and_active_code_uniqueness_per_stock(): void
    {
        $this->actingAs(User::factory()->create());
        $firstStock = Branch::create(['name' => 'Filial A', 'code' => 'A'])->stock;
        $secondStock = Branch::create(['name' => 'Filial B', 'code' => 'B'])->stock;
        $payload = ['stock_id' => $firstStock->id, 'name' => 'Depósito', 'code' => 'DEP', 'type' => 'storage'];

        $this->postJson('/api/v1/stock-locations', array_merge($payload, ['stock_id' => 'not-a-uuid']))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_id');
        $location = $this->postJson('/api/v1/stock-locations', $payload)->assertCreated()->json();
        $this->postJson('/api/v1/stock-locations', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/stock-locations', array_merge($payload, ['stock_id' => $secondStock->id]))
            ->assertCreated();
        $this->putJson('/api/v1/stock-locations/'.$location['id'], array_merge($payload, ['name' => 'Alterada']))
            ->assertOk();
    }

    public function test_location_listing_filters_by_stock_and_searches_name(): void
    {
        $this->actingAs(User::factory()->create());
        $firstStock = Branch::create(['name' => 'Filial A', 'code' => 'A'])->stock;
        $secondStock = Branch::create(['name' => 'Filial B', 'code' => 'B'])->stock;
        StockLocation::create(['stock_id' => $firstStock->id, 'name' => 'Depósito Azul', 'code' => 'AZ', 'type' => 'storage']);
        StockLocation::create(['stock_id' => $firstStock->id, 'name' => 'Prateleira', 'code' => 'PR', 'type' => 'shelf']);
        StockLocation::create(['stock_id' => $secondStock->id, 'name' => 'Depósito Verde', 'code' => 'VD', 'type' => 'storage']);

        $this->getJson('/api/v1/stock-locations?stock_id='.$firstStock->id.'&search=Azul')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Depósito Azul');
    }
}
