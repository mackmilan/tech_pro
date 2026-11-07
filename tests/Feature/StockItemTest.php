<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_item_belongs_to_stock_and_product_and_stores_quantity(): void
    {
        $stock = Branch::create(['name' => 'Filial Centro', 'code' => 'CENTRO'])->stock;
        $product = Product::create([
            'sku' => 'ITEM-1',
            'name' => 'Celular',
            'category' => 'Celulares',
            'cost_price' => 100,
            'sale_price' => 150,
        ]);

        $item = $stock->items()->create(['product_id' => $product->id, 'quantity' => 8]);

        $this->assertInstanceOf(StockItem::class, $item);
        $this->assertSame($stock->id, $item->stock->id);
        $this->assertSame($product->id, $item->product->id);
        $this->assertSame(8, $item->quantity);
        $this->assertSame(1, $stock->items()->count());
    }

    public function test_stock_can_have_multiple_items_for_the_same_product_when_they_are_distinct_variants(): void
    {
        $stock = Branch::create(['name' => 'Filial Centro', 'code' => 'CENTRO'])->stock;
        $product = Product::create([
            'sku' => 'ITEM-2',
            'name' => 'Tablet',
            'category' => 'Tablets',
            'cost_price' => 100,
            'sale_price' => 150,
        ]);

        $first = $stock->items()->create(['product_id' => $product->id, 'quantity' => 4]);
        $second = $stock->items()->create(['product_id' => $product->id, 'quantity' => 6]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $stock->items()->where('product_id', $product->id)->count());
    }
}
