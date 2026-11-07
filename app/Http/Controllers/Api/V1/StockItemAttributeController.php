<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductAttribute;
use App\Models\StockItem;
use App\Models\StockItemAttributeValue;
use App\Models\StockUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockItemAttributeController extends Controller
{
    public function index(StockItem $stockItem)
    {
        return response()->json(['data' => $this->attributePayload($stockItem)]);
    }

    public function update(Request $request, StockItem $stockItem)
    {
        $data = $request->validate([
            'units' => ['sometimes', 'array'],
            'units.*.condition_id' => ['required_with:units', 'uuid', 'exists:product_conditions,id'],
            'units.*.unit_cost' => ['required_with:units', 'numeric', 'min:0'],
            'units.*.attributes' => ['sometimes', 'array'],
            'units.*.attributes.*.product_attribute_id' => ['required_with:units.*.attributes', 'uuid'],
            'units.*.attributes.*.value' => ['present_with:units.*.attributes'],
            'attributes' => ['required_without:units', 'array'],
            'attributes.*.product_attribute_id' => ['required_with:attributes', 'uuid', 'distinct'],
            'attributes.*.value' => ['present_with:attributes'],
        ]);

        $definitions = $this->applicableAttributes($stockItem)->keyBy('id');
        $submittedUnits = $data['units'] ?? [];
        $submitted = collect($data['attributes'] ?? [])->keyBy('product_attribute_id');
        $existingUnits = $stockItem->units()->where('active', true)->get()->keyBy('id');
        if ($submittedUnits && count($submittedUnits) !== $existingUnits->count()) throw ValidationException::withMessages(['units' => 'A quantidade de unidades enviada deve corresponder ao estoque atual.']);
        $resolvedUnits = collect();
        foreach ($submittedUnits as $unitIndex => $unit) {
            $unitModel = isset($unit['id']) ? $existingUnits->get($unit['id']) : $existingUnits->values()->get($unitIndex);
            if (! $unitModel) throw ValidationException::withMessages(["units.{$unitIndex}.id" => 'A unidade não pertence a este item de estoque.']);
            $resolvedUnits->put($unitModel->id, [$unitModel, $unit]);
            foreach ($unit['attributes'] ?? [] as $attributeIndex => $entry) {
                $attribute = $definitions->get($entry['product_attribute_id']);
                if (! $attribute || ! ($attribute->entity_type === 'produto' && $attribute->differentiate_stock || $attribute->entity_type === 'estoque' && $attribute->per_unit)) {
                    throw ValidationException::withMessages(["units.{$unitIndex}.attributes.{$attributeIndex}.product_attribute_id" => 'O atributo não pode ser alterado por unidade.']);
                }
                $this->validateAttributeValue($attribute, $entry['value'], "units.{$unitIndex}.attributes.{$attributeIndex}.value");
            }
        }
        foreach ($data['attributes'] ?? [] as $index => $entry) {
            $attribute = $definitions->get($entry['product_attribute_id']);
            if ($attribute === null) throw ValidationException::withMessages(["attributes.{$index}.product_attribute_id" => 'Um ou mais atributos não estão disponíveis para este item.']);
            if ($attribute->entity_type === 'produto' && $attribute->differentiate_stock || $attribute->entity_type === 'estoque' && $attribute->per_unit) {
                throw ValidationException::withMessages(["attributes.{$index}.value" => 'Este atributo deve ser informado por unidade.']);
            }
            $this->validateAttributeValue($attribute, $entry['value'], "attributes.{$index}.value");
        }
        foreach ($definitions as $id => $attribute) {
            if (! $attribute->required) continue;
            if ($attribute->entity_type === 'produto' && $attribute->differentiate_stock || $attribute->entity_type === 'estoque' && $attribute->per_unit) {
                foreach ($submittedUnits as $unitIndex => $unit) {
                    $value = collect($unit['attributes'] ?? [])->firstWhere('product_attribute_id', $id)['value'] ?? null;
                    if ($this->isEmpty($value)) throw ValidationException::withMessages(["units.{$unitIndex}.attributes" => "O atributo {$attribute->name} é obrigatório para cada unidade."]);
                }
            } elseif (! $submitted->has($id) || $this->isEmpty($submitted->get($id)['value'])) {
                throw ValidationException::withMessages(['attributes' => "O atributo {$attribute->name} é obrigatório."]);
            }
        }

        DB::transaction(function () use ($stockItem, $definitions, $submitted, $submittedUnits, $resolvedUnits) {
            foreach ($definitions as $id => $attribute) {
                if ($attribute->entity_type === 'produto' && $attribute->differentiate_stock || $attribute->entity_type === 'estoque' && $attribute->per_unit) continue;
                $entry = $submitted->get($id);
                if ($entry === null) {
                    $stockItem->attributeValues()->where('product_attribute_id', $id)->delete();
                    continue;
                }
                $this->persistSharedValue($stockItem, $id, $entry['value']);
            }
            foreach ($submittedUnits as $unitIndex => $unit) {
                [$unitModel] = $resolvedUnits->values()->get($unitIndex);
                if (array_key_exists('condition_id', $unit)) $unitModel->condition_id = $unit['condition_id'];
                if (array_key_exists('unit_cost', $unit)) $unitModel->unit_cost = $unit['unit_cost'];
                $values = collect($unit['attributes'] ?? [])->keyBy('product_attribute_id');
                $attributes = $unitModel->attributes ?? [];
                foreach ($definitions as $id => $attribute) {
                    if (! ($attribute->entity_type === 'produto' && $attribute->differentiate_stock || $attribute->entity_type === 'estoque' && $attribute->per_unit)) continue;
                    $entry = $values->get($id);
                    if ($entry !== null) $attributes[$id] = $entry['value'];
                }
                $unitModel->attributes = $attributes;
                $unitModel->save();
            }
        });

        return response()->json(['data' => $this->attributePayload($stockItem->refresh())]);
    }

    private function persistSharedValue(StockItem $stockItem, string $attributeId, mixed $value): void
    {
        $stored = StockItemAttributeValue::withTrashed()->firstOrNew([
            'stock_item_id' => $stockItem->id,
            'product_attribute_id' => $attributeId,
        ]);
        $stored->value = $value;
        $stored->active = true;
        if ($stored->exists && $stored->trashed()) $stored->restore();
        else $stored->save();
    }

    private function applicableAttributes(StockItem $stockItem)
    {
        $product = $stockItem->product()->with('categoryRelation')->firstOrFail();
        $categoryIds = collect();
        $category = $product->categoryRelation;
        while ($category) {
            $categoryIds->push($category->id);
            $category = $category->parent;
        }

        return ProductAttribute::query()
            ->where('active', true)
            ->where(function ($query) use ($categoryIds) {
                $query->whereDoesntHave('categories')
                    ->orWhereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds));
            })
            ->where(fn ($query) => $query->where(fn ($stockAttributes) => $stockAttributes->where('entity_type', 'estoque')->where('per_unit', false))
                ->orWhere(fn ($productAttributes) => $productAttributes->where('entity_type', 'produto')->where('differentiate_stock', true)))
            ->orderBy('name')
            ->get();
    }

    private function attributePayload(StockItem $stockItem): array
    {
        $values = $stockItem->attributeValues()->get()->keyBy('product_attribute_id');

        $definitions = $this->applicableAttributes($stockItem)->keyBy('id');
        $units = $stockItem->units()->where('active', true)->get();
        return $definitions->map(fn (ProductAttribute $attribute) => [
                'product_attribute_id' => $attribute->id,
                'name' => $attribute->name,
                'type' => $attribute->differentiate_stock && $attribute->type === 'selecao_multipla' ? 'selecao_unica' : $attribute->type,
                'options' => $attribute->options,
                'required' => (bool) $attribute->required,
                'differentiate_stock' => (bool) $attribute->differentiate_stock,
                'per_unit' => (bool) $attribute->per_unit,
                'value' => ($attribute->entity_type === 'produto' && $attribute->differentiate_stock || $attribute->entity_type === 'estoque' && $attribute->per_unit)
                    ? $units->map(fn (StockUnit $unit) => $unit->attributes[$attribute->id] ?? null)->unique()->count() === 1
                        ? $units->first()?->attributes[$attribute->id] ?? null
                        : $units->map(fn (StockUnit $unit) => $unit->attributes[$attribute->id] ?? null)->values()->all()
                    : $values->get($attribute->id)?->value,
            ])
            ->values()
            ->all();
    }

    private function validateAttributeValue(ProductAttribute $attribute, mixed $value, string $errorKey): void
    {
        if ($attribute->differentiate_stock && $attribute->type === 'selecao_multipla') {
            $attribute->type = 'selecao_unica';
        }
        if ($this->isEmpty($value)) return;
        $valid = match ($attribute->type) {
            'texto' => is_string($value), 'numerico' => is_numeric($value), 'booleano' => is_bool($value),
            'selecao_unica' => is_string($value) && in_array($value, $attribute->options ?? [], true),
            'selecao_multipla' => is_array($value) && count($value) > 0 && count(array_diff($value, $attribute->options ?? [])) === 0 && count($value) === count(array_unique($value)),
            default => false,
        };
        if (! $valid) throw ValidationException::withMessages([$errorKey => "O valor informado para {$attribute->name} não corresponde ao tipo ou às opções configuradas."]);
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
