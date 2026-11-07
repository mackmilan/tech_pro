<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCondition;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\StockItemAttributeValue;
use App\Models\StockLocation;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementController extends CrudController
{
    protected string $model = StockMovement::class;

    protected array $rules = [
        'stock_id' => 'required|uuid|exists:stocks,id',
        'stock_location_id' => 'required|uuid|exists:stock_locations,id',
        'product_id' => 'required|uuid|exists:products,id',
        'type' => 'required|in:in,out,adjustment',
        'source' => 'sometimes|nullable|string|in:fornecedor,aparelho',
        'quantity' => 'required|integer|min:1',
        'condition_id' => 'sometimes|nullable|uuid|exists:product_conditions,id',
        'unit_cost' => 'sometimes|nullable|numeric|min:0',
        'units' => 'sometimes|array',
        'units.*.condition_id' => 'required_with:units|uuid|exists:product_conditions,id',
        'units.*.unit_cost' => 'required_with:units|numeric|min:0',
        'units.*.stock_location_id' => 'sometimes|nullable|uuid|exists:stock_locations,id',
        'units.*.source' => 'sometimes|nullable|string|in:fornecedor,aparelho',
        'units.*.attributes' => 'sometimes|array',
        'units.*.attributes.*.product_attribute_id' => 'required_with:units.*.attributes|uuid',
        'units.*.attributes.*.value' => 'present_with:units.*.attributes',
        'reference' => 'nullable|string|max:100',
        'notes' => 'nullable|string',
        'attributes' => 'sometimes|array',
        'attributes.*.product_attribute_id' => 'required_with:attributes|uuid|distinct',
        'attributes.*.value' => 'present_with:attributes',
    ];

    public function store(Request $request)
    {
        $data = $request->validate($this->rules);
        if (($data['type'] ?? null) === 'in' && ! isset($data['units'])) {
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
        if (($data['type'] ?? null) === 'in' && isset($data['units']) && count($data['units']) !== $data['quantity']) {
            throw ValidationException::withMessages(['units' => "Informe exatamente {$data['quantity']} unidades para esta entrada."]);
        }
        $stock = Stock::findOrFail($data['stock_id']);
        $location = StockLocation::findOrFail($data['stock_location_id']);
        if ($location->stock_id !== $stock->id) {
            throw ValidationException::withMessages(['stock_location_id' => 'A localização não pertence ao estoque informado.']);
        }

        return response()->json(DB::transaction(function () use ($data, $stock) {
            if ($data['type'] === 'in') {
                $movement = app(\App\Services\StockEntryService::class)->recordInbound($stock, $data);
            } else {
                $movementData = collect($data)->except(['stock_id', 'attributes', 'units'])->all();
                $movement = $stock->movements()->create($movementData);
            }

            return $movement->load(['stock', 'stockLocation', 'product']);
        }), 201);
    }

    private function applicableStockAttributes(StockItem $item)
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

    private function assertActiveCondition(string $conditionId, string $errorKey): void
    {
        if (! ProductCondition::query()->whereKey($conditionId)->where('active', true)->exists()) {
            throw ValidationException::withMessages([$errorKey => 'A condição selecionada está inativa ou indisponível.']);
        }
    }

    private function assertLegacyInboundFields(array $data): void
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

    private function normalizeEntryUnits(array $data): array
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

    private function validateEntryAttributes(StockItem $item, int $quantity, array $submitted, string $path = 'attributes'): void
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

    private function combinedUnitAttributes(array $units, array $shared, StockItem $item): array
    {
        $combined = $shared;
        foreach ($this->applicableStockAttributes($item) as $attribute) {
            if ($attribute->entity_type === 'estoque' && $attribute->per_unit) {
                $combined[] = [
                    'product_attribute_id' => $attribute->id,
                    'value' => array_map(fn ($unit) => collect($unit['attributes'] ?? [])->firstWhere('product_attribute_id', $attribute->id)['value'] ?? null, $units),
                ];

                continue;
            }
            if ($attribute->differentiate_stock) {
                $values = array_map(fn ($unit) => collect($unit['attributes'] ?? [])->firstWhere('product_attribute_id', $attribute->id)['value'] ?? null, $units);
                if (collect($values)->contains(fn ($value) => $this->isEmpty($value))) {
                    throw ValidationException::withMessages(['units' => "Informe {$attribute->name} para cada unidade."]);
                }
                if (count(array_unique(array_map(fn ($value) => json_encode($value), $values))) === 1) {
                    $combined[] = ['product_attribute_id' => $attribute->id, 'value' => $values[0]];
                }
            }
        }

        return $combined;
    }

    private function attributesForUnit(array $submitted, int $unitIndex, int $quantity, StockItem $item): array
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

    private function createStockUnits(
        StockItem $item,
        int $quantity,
        array $submitted,
        ?string $conditionId,
        mixed $unitCost,
        ?string $stockLocationId = null,
        ?string $source = null
    ): void
    {
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
                $attribute = $definitions->firstWhere('id', $attributeId);
                $attributes[$attributeId] = ($attribute?->entity_type === 'estoque' && $attribute->per_unit) || $attribute?->differentiate_stock
                    ? $value
                    : $value;
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

    private function validateAttributeValue(ProductAttribute $attribute, mixed $value, string $errorKey): void
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

    private function validatePerUnitValue(ProductAttribute $attribute, mixed $value, string $errorKey): void
    {
        if ($this->isEmpty($value)) {
            throw ValidationException::withMessages([$errorKey => "O atributo {$attribute->name} é obrigatório para cada unidade."]);
        }
        $this->validateAttributeValue($attribute, $value, $errorKey);
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function saveStockItemAttributes(StockItem $item, array $submitted): void
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

    /** Resolve or create the stock item matching the selected stock-controlling values. */
    private function stockItemForEntry(Stock $stock, array $data, array $unitAttributes = [], ?int $unitIndex = null): StockItem
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
        $item = $stock->items()->create(['product_id' => $product->id, 'quantity' => 0]);
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
