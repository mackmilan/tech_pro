<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCondition;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\StockItemAttributeValue;
use App\Models\StockLocation;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StockEntryService
{
    public function recordInbound(Stock $stock, array $data): StockMovement
    {
        $movementData = collect($data)->except(['stock_id', 'attributes', 'units'])->all();
        $movementData['type'] = 'in';

        if (isset($data['units'][0])) {
            $movementData['condition_id'] ??= $data['units'][0]['condition_id'] ?? null;
            $movementData['unit_cost'] ??= $data['units'][0]['unit_cost'] ?? null;
        }

        $movement = $stock->movements()->create($movementData);
        $units = $this->normalizeEntryUnits($data);

        if (! isset($data['units'])) {
            $this->assertLegacyInboundFields($data);
            $this->assertActiveCondition($data['condition_id'], 'condition_id');
            $movement->condition_id = $data['condition_id'];
            $movement->unit_cost = $data['unit_cost'];
            $movement->save();

            $item = $this->stockItemForEntry($stock, $data, [], null);
            $this->validateEntryAttributes($item, count($units), $data['attributes'] ?? [], 'shared');

            $sharedAttributes = array_values(array_filter($data['attributes'] ?? [], function ($entry) use ($item) {
                $attribute = $this->applicableStockAttributes($item)->firstWhere('id', $entry['product_attribute_id']);

                return ! ($attribute && ($attribute->differentiate_stock || ($attribute->entity_type === 'estoque' && $attribute->per_unit)));
            }));
            $this->saveStockItemAttributes($item, $sharedAttributes);
            $item->increment('quantity', count($units));

            foreach ($units as $unitIndex => $unit) {
                $unitAttributes = $this->attributesForUnit($data['attributes'] ?? [], $unitIndex, count($units), $item);
                $unitVariations = collect($unit['attributes'] ?? [])->filter(function ($entry) use ($item) {
                    $attribute = $this->applicableStockAttributes($item)->firstWhere('id', $entry['product_attribute_id']);

                    return $attribute && $attribute->differentiate_stock;
                })->all();

                if ($unitVariations) {
                    $this->validateEntryAttributes($item, 1, $unitVariations, "units.{$unitIndex}.attributes");
                    $item = $this->stockItemForEntry($stock, $data, $unitVariations, $unitIndex);
                    $this->saveStockItemAttributes($item, $unitVariations);
                }

                $unitAttributes = array_values(array_filter($unitAttributes, function ($entry) use ($item) {
                    $attribute = $this->applicableStockAttributes($item)->firstWhere('id', $entry['product_attribute_id']);

                    return ! ($attribute && $attribute->differentiate_stock);
                }));

                $this->validateEntryAttributes($item, 1, array_merge($unitAttributes, $unitVariations), "units.{$unitIndex}.attributes");
                $this->createStockUnits(
                    $item,
                    1,
                    array_merge($unitAttributes, $unitVariations),
                    $unit['condition_id'],
                    $unit['unit_cost'],
                    $unit['stock_location_id'] ?? $data['stock_location_id'],
                    $unit['source'] ?? $data['source'] ?? null
                );
            }
        } else {
            $resolvedUnits = [];
            foreach ($units as $unitIndex => $unit) {
                $this->assertActiveCondition($unit['condition_id'], "units.{$unitIndex}.condition_id");
                $item = $this->stockItemForEntry($stock, $data, $unit['attributes'] ?? [], $unitIndex);
                $unitAttributes = array_merge($data['attributes'] ?? [], $unit['attributes'] ?? []);
                $unitVariations = collect($unit['attributes'] ?? [])->keyBy('product_attribute_id');
                $sharedAttributes = collect($data['attributes'] ?? [])->keyBy('product_attribute_id');

                $productVariations = $this->applicableStockAttributes($item)
                    ->filter(fn ($attribute) => $attribute->differentiate_stock)
                    ->map(function ($attribute) use ($unitVariations, $sharedAttributes) {
                        return $unitVariations->get($attribute->id) ?? $sharedAttributes->get($attribute->id);
                    })
                    ->filter()
                    ->values()
                    ->all();

                $unitAttributes = array_values(array_filter($unitAttributes, function ($entry) use ($item) {
                    $attribute = $this->applicableStockAttributes($item)->firstWhere('id', $entry['product_attribute_id']);

                    return ! ($attribute && $attribute->differentiate_stock);
                }));

                $this->validateEntryAttributes($item, 1, array_merge($unitAttributes, $productVariations), "units.{$unitIndex}.attributes");
                $this->saveStockItemAttributes($item, $unitAttributes);
                $resolvedUnits[] = [$item, $unit, array_merge($unitAttributes, $productVariations)];
            }

            foreach (collect($resolvedUnits)->groupBy(fn ($resolved) => $resolved[0]->id) as $group) {
                $group->first()[0]->increment('quantity', $group->count());
            }

            foreach ($resolvedUnits as [$item, $unit, $unitAttributes]) {
                $this->createStockUnits(
                    $item,
                    1,
                    $unitAttributes,
                    $unit['condition_id'],
                    $unit['unit_cost'],
                    $unit['stock_location_id'] ?? $data['stock_location_id'],
                    $unit['source'] ?? $data['source'] ?? null
                );
            }
        }

        return $movement;
    }

    public function applicableStockAttributes(StockItem $item): Collection
    {
        $categoryIds = collect();
        $category = $item->product()->with('categoryRelation')->firstOrFail()->categoryRelation;
        while ($category) {
            $categoryIds->push($category->id);
            $category = $category->parent;
        }

        return ProductAttribute::query()->where('active', true)
            ->where(function ($query) use ($categoryIds) {
                $query->whereDoesntHave('categories')
                    ->orWhereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds));
            })
            ->where(function ($query) {
                $query->where('differentiate_stock', true)
                    ->orWhere('entity_type', 'estoque');
            })->get();
    }

    public function assertActiveCondition(string $conditionId, string $errorKey): void
    {
        if (! ProductCondition::query()->whereKey($conditionId)->where('active', true)->exists()) {
            throw ValidationException::withMessages([$errorKey => 'A condição selecionada está inativa ou indisponível.']);
        }
    }

    public function assertLegacyInboundFields(array $data): void
    {
        $errors = [];
        if (empty($data['condition_id'])) {
            $errors['condition_id'][] = 'Informe a condição para a entrada.';
        }
        if (! array_key_exists('unit_cost', $data) || $data['unit_cost'] === null || $data['unit_cost'] === '') {
            $errors['unit_cost'][] = 'Informe o custo unitário para a entrada.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function normalizeEntryUnits(array $data): array
    {
        $units = $data['units'] ?? null;
        if ($units === null) {
            return array_fill(0, $data['quantity'], [
                'condition_id' => $data['condition_id'] ?? null,
                'unit_cost' => $data['unit_cost'] ?? null,
                'attributes' => $data['attributes'] ?? [],
            ]);
        }
        if (count($units) !== $data['quantity']) {
            throw ValidationException::withMessages(['units' => "Informe exatamente {$data['quantity']} unidades para esta entrada."]);
        }

        return array_values($units);
    }

    public function validateEntryAttributes(StockItem $item, int $quantity, array $submitted, string $path = 'attributes'): void
    {
        $isSharedPayload = $path === 'shared';
        $path = $isSharedPayload ? 'attributes' : $path;
        $definitions = $this->applicableStockAttributes($item)->keyBy('id');
        $values = collect($submitted)->keyBy('product_attribute_id');

        foreach ($submitted as $index => $entry) {
            $errorKey = "{$path}.{$index}.value";
            $attribute = $definitions->get($entry['product_attribute_id']);
            if ($attribute === null) {
                throw ValidationException::withMessages([$path => 'Um ou mais atributos não estão disponíveis para este produto.']);
            }
            $value = $entry['value'];
            if ($isSharedPayload && $attribute->entity_type === 'estoque' && $attribute->per_unit) {
                if (! is_array($value) || count($value) !== $quantity) {
                    throw ValidationException::withMessages([$errorKey => "Informe exatamente {$quantity} valores para {$attribute->name}, um por unidade."]);
                }
                foreach ($value as $unitIndex => $unitValue) {
                    $this->validatePerUnitValue($attribute, $unitValue, "{$errorKey}.{$unitIndex}");
                }

                continue;
            }
            if ($attribute->entity_type === 'estoque' && $attribute->per_unit && $path === 'attributes') {
                if (! is_array($value) || count($value) !== $quantity) {
                    throw ValidationException::withMessages([$errorKey => "Informe exatamente {$quantity} valores para {$attribute->name}, um por unidade."]);
                }
                foreach ($value as $unitIndex => $unitValue) {
                    $this->validatePerUnitValue($attribute, $unitValue, "{$errorKey}.{$unitIndex}");
                }

                continue;
            }
            if ($attribute->entity_type === 'estoque' && $attribute->per_unit && str_starts_with($path, 'units.')) {
                $this->validatePerUnitValue($attribute, $value, $errorKey);

                continue;
            }
            if ($attribute->differentiate_stock && $this->isEmpty($value)) {
                throw ValidationException::withMessages([$errorKey => "O atributo {$attribute->name} é obrigatório para identificar a variação."]);
            }
            if ($attribute->required && $this->isEmpty($value)) {
                throw ValidationException::withMessages([$errorKey => "O atributo {$attribute->name} é obrigatório."]);
            }
            if (! $this->isEmpty($value)) {
                $this->validateAttributeValue($attribute, $value, $errorKey);
            }
        }

        foreach ($definitions as $id => $attribute) {
            if (! $isSharedPayload && $attribute->required && $attribute->entity_type === 'estoque' && $attribute->per_unit && $path === 'attributes') {
                $perUnit = $values->get($id)['value'] ?? null;
                if (! is_array($perUnit) || count($perUnit) !== $quantity) {
                    throw ValidationException::withMessages([$path => "Informe {$attribute->name} para cada unidade recebida."]);
                }

                continue;
            }
            if ($attribute->required && ! $attribute->differentiate_stock && (! $values->has($id) || $this->isEmpty($values->get($id)['value']))) {
                throw ValidationException::withMessages([$path => "O atributo {$attribute->name} é obrigatório."]);
            }
        }
    }

    public function attributesForUnit(array $submitted, int $unitIndex, int $quantity, StockItem $item): array
    {
        $definitions = $this->applicableStockAttributes($item)->keyBy('id');

        return collect($submitted)->map(function ($entry) use ($definitions, $unitIndex) {
            $definition = $definitions->get($entry['product_attribute_id']);
            if ($definition?->entity_type === 'estoque' && $definition->per_unit && is_array($entry['value'])) {
                $entry['value'] = array_values($entry['value'])[$unitIndex] ?? null;
            }

            return $entry;
        })->all();
    }

    public function createStockUnits(
        StockItem $item,
        int $quantity,
        array $submitted,
        ?string $conditionId,
        mixed $unitCost,
        ?string $stockLocationId = null,
        ?string $source = null
    ): void {
        $definitions = $this->applicableStockAttributes($item);
        $submittedValues = collect($submitted)->keyBy('product_attribute_id');
        $unitAttributes = [];

        foreach ($definitions as $attribute) {
            $value = $submittedValues->get($attribute->id)['value'] ?? null;
            if (($attribute->entity_type === 'estoque' && $attribute->per_unit) || $attribute->differentiate_stock) {
                if (! $this->isEmpty($value)) {
                    $unitAttributes[$attribute->id] = $value;
                }

                continue;
            }
            if ($attribute->entity_type === 'estoque' && $attribute->type === 'selecao_multipla' && is_array($value)) {
                $value = array_values(array_intersect($attribute->options ?? [], $value));
            }
            if (! $this->isEmpty($value)) {
                $unitAttributes[$attribute->id] = $value;
            }
        }

        for ($unitIndex = 0; $unitIndex < $quantity; $unitIndex++) {
            $attributes = [];
            foreach ($unitAttributes as $attributeId => $value) {
                $attributes[$attributeId] = $value;
            }
            $item->units()->create([
                'condition_id' => $conditionId,
                'stock_location_id' => $stockLocationId,
                'source' => $source,
                'unit_cost' => $unitCost,
                'attributes' => $attributes,
            ]);
        }
    }

    public function validateAttributeValue(ProductAttribute $attribute, mixed $value, string $errorKey): void
    {
        if ($this->isEmpty($value)) {
            return;
        }
        $type = $attribute->differentiate_stock && $attribute->type === 'selecao_multipla' ? 'selecao_unica' : $attribute->type;
        $valid = match ($type) {
            'texto' => is_string($value),
            'numerico' => is_numeric($value),
            'booleano' => is_bool($value),
            'selecao_unica' => is_string($value) && in_array($value, $attribute->options ?? [], true),
            'selecao_multipla' => is_array($value) && count($value) > 0 && count(array_diff($value, $attribute->options ?? [])) === 0 && count($value) === count(array_unique($value)),
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages([$errorKey => "O valor informado para {$attribute->name} não corresponde ao tipo ou às opções configuradas."]);
        }
    }

    public function validatePerUnitValue(ProductAttribute $attribute, mixed $value, string $errorKey): void
    {
        if ($this->isEmpty($value)) {
            throw ValidationException::withMessages([$errorKey => "O atributo {$attribute->name} é obrigatório para cada unidade."]);
        }
        $this->validateAttributeValue($attribute, $value, $errorKey);
    }

    public function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    public function saveStockItemAttributes(StockItem $item, array $submitted): void
    {
        $definitions = $this->applicableStockAttributes($item)->keyBy('id');
        $values = collect($submitted)->keyBy('product_attribute_id');

        foreach ($definitions as $id => $attribute) {
            if ($attribute->entity_type === 'estoque' && $attribute->per_unit) {
                continue;
            }
            $entry = $values->get($id);
            if ($entry === null) {
                continue;
            }
            $value = $entry['value'];
            if ($attribute->differentiate_stock && is_array($value)) {
                $value = $value[0] ?? null;
            }
            if ($attribute->entity_type === 'estoque' && $attribute->type === 'selecao_multipla' && is_array($value)) {
                $value = array_values(array_intersect($attribute->options ?? [], $value));
            }
            $stored = StockItemAttributeValue::withTrashed()->firstOrNew([
                'stock_item_id' => $item->id,
                'product_attribute_id' => $id,
            ]);
            $stored->value = $value;
            $stored->active = true;
            if ($stored->exists && $stored->trashed()) {
                $stored->restore();
            } else {
                $stored->save();
            }
        }
    }

    public function stockItemForEntry(Stock $stock, array $data, array $unitAttributes = [], ?int $unitIndex = null): StockItem
    {
        $product = Product::with('categoryRelation')->findOrFail($data['product_id']);
        $categoryIds = collect();
        $category = $product->categoryRelation;
        while ($category) {
            $categoryIds->push($category->id);
            $category = $category->parent;
        }
        $controlled = ProductAttribute::query()
            ->where('active', true)->where('differentiate_stock', true)
            ->where(function ($query) use ($categoryIds) {
                $query->whereDoesntHave('categories')
                    ->orWhereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds));
            })->orderBy('id')->get();
        $values = collect($data['attributes'] ?? [])->keyBy('product_attribute_id');
        $unitValues = collect($unitAttributes)->keyBy('product_attribute_id');
        $variant = [];

        foreach ($controlled as $attribute) {
            $unitValues = collect($unitAttributes)->keyBy('product_attribute_id');
            $value = $unitIndex === null
                ? ($unitValues->get($attribute->id)['value'] ?? $values->get($attribute->id)['value'] ?? null)
                : ($unitValues->get($attribute->id)['value'] ?? $values->get($attribute->id)['value'] ?? null);
            $attributeIndex = collect($unitAttributes)->search(fn ($entry) => $entry['product_attribute_id'] === $attribute->id);
            $errorKey = $attributeIndex !== false && $unitIndex !== null
                ? "units.{$unitIndex}.attributes.{$attributeIndex}.value"
                : 'attributes.'.collect($data['attributes'] ?? [])->search(fn ($entry) => $entry['product_attribute_id'] === $attribute->id).'.value';

            if ($attribute->differentiate_stock && $unitIndex !== null && ! $unitValues->has($attribute->id) && $values->has($attribute->id)) {
                $value = $values->get($attribute->id)['value'];
                $index = collect($data['attributes'] ?? [])->search(fn ($entry) => $entry['product_attribute_id'] === $attribute->id);
                $this->validateAttributeValue($attribute, $value, "attributes.{$index}.value");
            }
            if ($attribute->type === 'selecao_multipla' && is_array($value) && count($value) > 1) {
                throw ValidationException::withMessages([$errorKey => "Selecione apenas uma opção para {$attribute->name}."]);
            }
            if ($attribute->type === 'selecao_multipla' && is_array($value)) {
                $value = $value[0] ?? null;
            }
            if ($value === null || $value === '' || $value === []) {
                throw ValidationException::withMessages([$errorKey => "O atributo {$attribute->name} é obrigatório para identificar a variação do estoque."]);
            }
            if ($attribute->type === 'selecao_multipla') {
                $value = array_values(array_intersect($attribute->options ?? [], (array) $value))[0] ?? null;
            }
            $variant[$attribute->id] = $value;
        }

        if ($controlled->isEmpty()) {
            return $stock->items()->firstOrCreate(['product_id' => $product->id], ['quantity' => 0]);
        }

        $candidates = $stock->items()->where('product_id', $product->id)->with('attributeValues')->get();
        foreach ($candidates as $candidate) {
            foreach ($variant as $attributeId => $value) {
                if ($candidate->attributeValues->firstWhere('product_attribute_id', $attributeId)?->value !== $value) {
                    continue 2;
                }
            }

            return $candidate;
        }

        $item = $stock->items()->create(['product_id' => $product->id], ['quantity' => 0]);
        foreach ($variant as $attributeId => $value) {
            $stored = StockItemAttributeValue::withTrashed()->firstOrNew(['stock_item_id' => $item->id, 'product_attribute_id' => $attributeId]);
            $stored->value = $value;
            $stored->active = true;
            if ($stored->exists && $stored->trashed()) {
                $stored->restore();
            } else {
                $stored->save();
            }
        }

        return $item;
    }
}

