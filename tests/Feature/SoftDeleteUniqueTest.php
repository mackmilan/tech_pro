<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCondition;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoftDeleteUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_unique_values_can_be_reused_after_soft_delete(): void
    {
        $this->assertUniqueValueIsReusable(Branch::class, ['name' => 'Filial', 'code' => 'BRANCH-UNIQUE'], 'code', 'BRANCH-UNIQUE');
        $this->assertUniqueValueIsReusable(Branch::class, ['name' => 'Filial', 'code' => 'BRANCH-UNIQUE-2', 'keycloak_organization_id' => 'org-unique'], 'keycloak_organization_id', 'org-unique');
        $this->assertUniqueValueIsReusable(Product::class, [
            'sku' => 'SKU-UNIQUE', 'name' => 'Produto', 'category' => 'Geral', 'sale_price' => 20,
        ], 'sku', 'SKU-UNIQUE');
        $this->assertUniqueValueIsReusable(Sale::class, [
            'branch_id' => Branch::create(['name' => 'Venda Branch', 'code' => 'SALE-BRANCH'])->id,
            'number' => 'SALE-UNIQUE', 'status' => 'completed', 'subtotal' => 20, 'total' => 20,
        ], 'number', 'SALE-UNIQUE');
        $this->assertUniqueValueIsReusable(PurchaseOrder::class, [
            'branch_id' => Branch::create(['name' => 'PO Branch', 'code' => 'PO-BRANCH'])->id,
            'supplier_id' => Supplier::create(['name' => 'PO Supplier'])->id,
            'number' => 'PO-UNIQUE', 'status' => 'draft', 'order_date' => '2026-11-06',
            'subtotal' => 100, 'total' => 100,
        ], 'number', 'PO-UNIQUE');
        $this->assertUniqueValueIsReusable(ServiceOrder::class, [
            'branch_id' => Branch::create(['name' => 'SO Branch', 'code' => 'SO-BRANCH'])->id,
            'customer_id' => \App\Models\Customer::create(['name' => 'SO Customer'])->id,
            'number' => 'OS-UNIQUE', 'status' => 'open', 'device_model' => 'iPhone 13',
            'defect_reported' => 'Tela quebrada',
        ], 'number', 'OS-UNIQUE');
        $this->assertUniqueValueIsReusable(Service::class, [
            'name' => 'Troca de tela', 'code' => 'SRV-UNIQUE', 'price' => 150,
        ], 'code', 'SRV-UNIQUE');
        $this->assertUniqueValueIsReusable(User::class, [
            'name' => 'Usuário', 'email' => 'reutilizar@example.com', 'password' => 'password',
        ], 'email', 'reutilizar@example.com');
        $this->assertUniqueValueIsReusable(User::class, [
            'name' => 'Usuário', 'email' => 'reutilizar-keycloak@example.com', 'password' => 'password', 'keycloak_id' => 'subject-unico',
        ], 'keycloak_id', 'subject-unico');
        $this->assertUniqueValueIsReusable(Brand::class, ['name' => 'Marca única'], 'name', 'Marca única');
        $this->assertUniqueValueIsReusable(ProductCondition::class, ['name' => 'Condição única'], 'name', 'Condição única');

        $branchForStock = Branch::create(['name' => 'Estoque Branch', 'code' => 'STOCK-BRANCH']);
        $stock = $branchForStock->stock;
        $stock->delete();
        $replacementStock = Stock::create(['branch_id' => $branchForStock->id]);
        $this->assertNotSame($stock->id, $replacementStock->id);

        $this->assertUniqueValueIsReusable(StockLocation::class, [
            'stock_id' => Branch::create(['name' => 'Local Branch', 'code' => 'LOCAL-BRANCH'])->stock->id,
            'name' => 'Local', 'code' => 'LOCATION-UNIQUE', 'type' => 'storage',
        ], 'code', 'LOCATION-UNIQUE');
    }

    public function test_api_rejects_duplicate_active_skus_and_location_codes(): void
    {
        $this->actingAs(User::factory()->create());
        $branch = Branch::create(['name' => 'Filial API', 'code' => 'API-UNIQUE']);
        $productPayload = [
            'sku' => 'API-SKU-1', 'name' => 'Produto API', 'category' => 'Geral', 'sale_price' => 20,
        ];
        $product = $this->postJson('/api/v1/products', $productPayload)->assertCreated()->json();
        $this->postJson('/api/v1/products', $productPayload)->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->putJson('/api/v1/products/'.$product['id'], $productPayload)->assertOk();
        $this->deleteJson('/api/v1/products/'.$product['id'])->assertNoContent();
        $this->postJson('/api/v1/products', $productPayload)->assertCreated();

        $locationPayload = [
            'stock_id' => $branch->stock->id, 'name' => 'Depósito API', 'code' => 'DEP', 'type' => 'storage',
        ];
        $location = $this->postJson('/api/v1/stock-locations', $locationPayload)->assertCreated()->json();
        $this->postJson('/api/v1/stock-locations', $locationPayload)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->deleteJson('/api/v1/stock-locations/'.$location['id'])->assertNoContent();
        $this->postJson('/api/v1/stock-locations', $locationPayload)->assertCreated();
    }

    private function assertUniqueValueIsReusable(string $modelClass, array $attributes, string $uniqueColumn, string $uniqueValue): void
    {
        $original = $modelClass::create($attributes);
        $original->delete();
        $replacementAttributes = $attributes;
        if ($modelClass === StockLocation::class) {
            $replacementAttributes['stock_id'] = Branch::create([
                'name' => 'Stock substituto', 'code' => 'STOCK-SUB-'.str()->uuid(),
            ])->stock->id;
        }
        $replacement = $modelClass::create($replacementAttributes);
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame(2, $modelClass::withTrashed()->where($uniqueColumn, $uniqueValue)->count());
    }
}
