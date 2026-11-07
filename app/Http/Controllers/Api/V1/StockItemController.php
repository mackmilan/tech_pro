<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ProductAttribute;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;

class StockItemController
{
    public function index(Request $request)
    {
        $query = StockItem::query()->with([
            'product',
            'stock.branch',
            'attributeValues.productAttribute',
            'units.condition',
            'units.stockLocation',
        ]);

        if ($request->filled('stock_id')) {
            $query->where('stock_id', $request->string('stock_id')->toString());
        }

        return response()->json($query->latest('updated_at')->paginate(min(100, max(1, $request->integer('per_page', 20))))->through(
            function (StockItem $item) {
                $categoryIds = collect();
                $category = $item->product?->categoryRelation;
                while ($category) {
                    $categoryIds->push($category->id);
                    $category = $category->parent;
                }
                $unitDefinitions = ProductAttribute::query()->where('active', true)
                    ->where(function ($query) use ($categoryIds) {
                        $query->whereDoesntHave('categories')
                            ->orWhereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds));
                    })
                    ->where(function ($query) {
                        $query->where(function ($stockAttributes) {
                            $stockAttributes->where('entity_type', 'estoque')->where('per_unit', true);
                        })->orWhere(function ($productAttributes) {
                            $productAttributes->where('entity_type', 'produto')->where('differentiate_stock', true);
                        });
                    })
                    ->get()->keyBy('id');
                $units = $item->units->where('active', true);
                $sharedValues = $item->attributeValues
                    ->filter(fn ($value) => $value->productAttribute?->active)
                    ->map(function ($value) use ($units) {
                        $attrValue = $value->value;
                        if (($value->productAttribute->differentiate_stock || $value->productAttribute->per_unit) && $units->isNotEmpty()) {
                            $unitValues = $units->map(fn ($unit) => $unit->attributes[$value->product_attribute_id] ?? null)
                                ->filter(fn ($v) => $v !== null && $v !== '');
                            if ($unitValues->isNotEmpty()) {
                                $attrValue = $unitValues->unique()->count() === 1
                                    ? $unitValues->first()
                                    : $unitValues->values()->all();
                            }
                        }

                        return [
                            'product_attribute_id' => $value->product_attribute_id,
                            'name' => $value->productAttribute->name,
                            'value' => $attrValue,
                            'type' => $value->productAttribute->type,
                            'options' => $value->productAttribute->options,
                            'required' => (bool) $value->productAttribute->required,
                            'differentiate_stock' => (bool) $value->productAttribute->differentiate_stock,
                            'per_unit' => (bool) $value->productAttribute->per_unit,
                        ];
                    })->values();
                $existingAttributeIds = $item->attributeValues->pluck('product_attribute_id');
                $missingDifferentiating = $unitDefinitions->filter(fn ($def) => ! $existingAttributeIds->contains($def->id));
                $missingValues = $missingDifferentiating->map(function ($def) use ($units) {
                    $unitValues = $units->map(fn ($unit) => $unit->attributes[$def->id] ?? null)
                        ->filter(fn ($v) => $v !== null && $v !== '');
                    $attrValue = $unitValues->unique()->count() === 1
                        ? $unitValues->first()
                        : ($unitValues->isNotEmpty() ? $unitValues->values()->all() : null);

                    return [
                        'product_attribute_id' => $def->id,
                        'name' => $def->name,
                        'value' => $attrValue,
                        'type' => $def->type,
                        'options' => $def->options,
                        'required' => (bool) $def->required,
                        'differentiate_stock' => (bool) $def->differentiate_stock,
                        'per_unit' => (bool) $def->per_unit,
                    ];
                })->values();
                $activeUnits = $item->units->where('active', true);
                $hasMissingUnitData = $activeUnits->contains(fn ($u) => ! $u->stock_location_id || ! $u->source);
                $latestMovement = null;
                if ($activeUnits->isEmpty() || $hasMissingUnitData) {
                    $latestMovement = StockMovement::where('stock_id', $item->stock_id)
                        ->where('product_id', $item->product_id)
                        ->where('type', 'in')
                        ->latest()
                        ->with('stockLocation')
                        ->first();
                }

                $distinctLocations = $activeUnits->map(fn ($u) => $u->stockLocation ?? $latestMovement?->stockLocation)->filter()->unique('id')->values();
                $distinctSources = $activeUnits->map(fn ($u) => $u->source ?? $latestMovement?->source)->filter()->unique()->values();

                $unitValues = $activeUnits->map(function ($unit) use ($unitDefinitions, $latestMovement) {
                    $loc = $unit->stockLocation ?? $latestMovement?->stockLocation;
                    $source = $unit->source ?? $latestMovement?->source;

                    return [
                        'id' => $unit->id,
                        'condition_id' => $unit->condition_id,
                        'condition' => $unit->condition?->only(['id', 'name']),
                        'stock_location_id' => $unit->stock_location_id ?? $latestMovement?->stock_location_id,
                        'stock_location' => $loc ? [
                            'id' => $loc->id,
                            'name' => $loc->name,
                            'code' => $loc->code,
                            'type' => $loc->type,
                        ] : null,
                        'source' => $source,
                        'unit_cost' => $unit->unit_cost,
                        'attributes' => collect($unit->attributes)->mapWithKeys(fn ($value, $id) => [$unitDefinitions->get($id)?->id ?? $id => $value])->all(),
                    ];
                })->values();

                $stockLocation = $distinctLocations->count() === 1 ? [
                    'id' => $distinctLocations->first()->id,
                    'name' => $distinctLocations->first()->name,
                    'code' => $distinctLocations->first()->code,
                    'type' => $distinctLocations->first()->type,
                ] : ($distinctLocations->isEmpty() && $latestMovement?->stockLocation ? [
                    'id' => $latestMovement->stockLocation->id,
                    'name' => $latestMovement->stockLocation->name,
                    'code' => $latestMovement->stockLocation->code,
                    'type' => $latestMovement->stockLocation->type,
                ] : null);

                $source = $distinctSources->count() === 1 ? $distinctSources->first() : (
                    $distinctSources->isEmpty() ? $latestMovement?->source : null
                );

                return [
                    'id' => $item->id,
                    'stock_id' => $item->stock_id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'units' => $unitValues,
                    'product' => $item->product,
                    'stock' => $item->stock,
                    'stock_location' => $stockLocation,
                    'stock_locations' => $distinctLocations->map(fn ($loc) => [
                        'id' => $loc->id,
                        'name' => $loc->name,
                        'code' => $loc->code,
                        'type' => $loc->type,
                    ])->all(),
                    'source' => $source,
                    'sources' => $distinctSources->all(),
                    'attributes' => $sharedValues->concat($missingValues)->unique('product_attribute_id')->values(),
                ];
            },
        ));

    }
}
