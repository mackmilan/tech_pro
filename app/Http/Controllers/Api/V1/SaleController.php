<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request) { return response()->json(Sale::with('items')->latest()->paginate($request->integer('per_page', 20))); }
    public function show(Sale $sale) { return response()->json($sale->load('items')); }

    public function store(Request $request)
    {
        $data = $request->validate([
            'stock_id' => 'required|uuid|exists:stocks,id', 'customer_id' => 'nullable|uuid|exists:customers,id',
            'discount' => 'sometimes|numeric|min:0', 'payment_method' => 'nullable|string|max:40',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.stock_location_id' => 'required|uuid|exists:stock_locations,id', 'items.*.quantity' => 'required|integer|min:1',
            'items.*.stock_unit_ids' => 'sometimes|array', 'items.*.stock_unit_ids.*' => 'required|uuid|distinct|exists:stock_units,id',
        ]);
        $sale = DB::transaction(function () use ($data) {
            $subtotal = 0; $lines = [];
            foreach ($data['items'] as $item) {
                $location = \App\Models\StockLocation::whereKey($item['stock_location_id'])->where('stock_id', $data['stock_id'])->first();
                if (! $location) throw ValidationException::withMessages(['items' => 'Cada localização deve pertencer ao estoque informado.']);
                $product = \App\Models\Product::with('categoryRelation')->findOrFail($item['product_id']);
                $available = StockMovement::where('stock_id', $data['stock_id'])->where('stock_location_id', $location->id)->where('product_id', $product->id)->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN quantity WHEN type = 'out' THEN -quantity ELSE quantity END),0) as balance")->value('balance');
                $unitIds = $item['stock_unit_ids'] ?? [];
                $categoryIds = collect();
                $category = $product->categoryRelation;
                while ($category) {
                    $categoryIds->push($category->id);
                    $category = $category->parent;
                }
                $unitDefinitions = \App\Models\ProductAttribute::query()->where('active', true)
                    ->where(function ($query) use ($categoryIds) {
                        $query->whereDoesntHave('categories')
                            ->orWhereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds));
                    })
                    ->where(fn ($query) => $query->where(fn ($stockAttributes) => $stockAttributes->where('entity_type', 'estoque')->where('per_unit', true))
                        ->orWhere(fn ($productAttributes) => $productAttributes->where('entity_type', 'produto')->where('differentiate_stock', true)))
                    ->get();
                $isTrackedUnit = $unitDefinitions->contains(fn ($definition) => $definition->entity_type === 'produto' && $definition->differentiate_stock)
                    || $unitDefinitions->contains(fn ($definition) => $definition->entity_type === 'estoque' && $definition->per_unit);
                if ($isTrackedUnit && count($unitIds) !== $item['quantity']) {
                    throw ValidationException::withMessages(['items' => "Selecione exatamente {$item['quantity']} unidades identificadas de {$product->name}."]);
                }
                if (! $isTrackedUnit && $available < $item['quantity']) throw ValidationException::withMessages(['items' => "Estoque insuficiente para {$product->name}."]);
                $unitRows = collect();
                if ($isTrackedUnit) {
                    $unitRows = \App\Models\StockUnit::query()->whereIn('id', $unitIds)
                        ->whereHas('stockItem', fn ($query) => $query->where('stock_id', $data['stock_id'])->where('product_id', $product->id))
                        ->where('active', true)->lockForUpdate()->get();
                    if ($unitRows->count() !== $item['quantity']) throw ValidationException::withMessages(['items' => "Uma ou mais unidades selecionadas de {$product->name} não estão disponíveis."]);
                    foreach ($unitRows as $unitRow) {
                        foreach ($unitDefinitions as $definition) {
                            if (! array_key_exists($definition->id, $unitRow->attributes ?? [])) {
                                throw ValidationException::withMessages(['items' => "Uma ou mais unidades não possuem o atributo {$definition->name}."]);
                            }
                        }
                    }
                }
                $unit = (float) $product->sale_price; $lineTotal = $unit * $item['quantity']; $subtotal += $lineTotal;
                $lines[] = [$item, $location, $unit, $lineTotal, $unitRows];
            }
            $discount = (float) ($data['discount'] ?? 0);
            if ($discount > $subtotal) throw ValidationException::withMessages(['discount' => 'Desconto não pode superar o subtotal.']);
            $sale = Sale::create(['branch_id' => \App\Models\Stock::findOrFail($data['stock_id'])->branch_id, 'customer_id' => $data['customer_id'] ?? null, 'number' => 'V-'.str()->uuid(), 'status' => 'completed', 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $subtotal - $discount, 'payment_method' => $data['payment_method'] ?? null, 'sold_at' => now()]);
            foreach ($lines as [$item, $location, $unit, $lineTotal, $unitRows]) {
                if ($unitRows->isNotEmpty()) {
                    foreach ($unitRows as $unitRow) {
                        $sale->items()->create(['product_id' => $item['product_id'], 'stock_unit_id' => $unitRow->id, 'quantity' => 1, 'unit_price' => $unit, 'total' => $unit]);
                        $unitRow->update(['active' => false]);
                    }
                } else {
                    $sale->items()->create(['product_id' => $item['product_id'], 'quantity' => $item['quantity'], 'unit_price' => $unit, 'total' => $lineTotal]);
                }
                StockMovement::create(['stock_id' => $data['stock_id'], 'stock_location_id' => $location->id, 'product_id' => $item['product_id'], 'type' => 'out', 'quantity' => $item['quantity'], 'reference' => $sale->number, 'notes' => 'Baixa automática por venda']);
            }
            return $sale->load('items');
        });
        return response()->json($sale, 201);
    }
}
