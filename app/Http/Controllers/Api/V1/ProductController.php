<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends CrudController
{
    protected string $model = Product::class;
    protected array $rules = ['sku' => 'required|string|max:80', 'name' => 'required|string|max:255', 'category' => 'required|string|max:100', 'category_id' => ['nullable', 'uuid', 'exists:categories,id'], 'brand' => 'nullable|string|max:100', 'brand_id' => ['nullable', 'uuid', 'exists:brands,id'], 'description' => 'nullable|string', 'sale_price' => 'required|numeric|min:0', 'active' => 'sometimes|boolean'];

    public function index(Request $request)
    {
        $products = Product::query()->with(['categoryRelation:id,name', 'brandRelation:id,name', 'images'])
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->string('category_id')->toString()))
            ->when($request->filled('brand_id'), fn ($query) => $query->where('brand_id', $request->string('brand_id')->toString()))
            ->when($request->has('active'), fn ($query) => $query->where('active', $request->boolean('active')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($products) => $products->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%'));
            })
            ->latest()
            ->paginate($request->integer('per_page', 20));
        return response()->json($products);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->validationRules($request));
        $product = Product::create($data)->load(['categoryRelation:id,name', 'brandRelation:id,name', 'images']);
        return response()->json($product, 201);
    }

    protected function loadRecord(Model $record): Model
    {
        return $record->load(['categoryRelation:id,name', 'brandRelation:id,name', 'images']);
    }

    protected function validationRules(Request $request, ?Model $record = null): array
    {
        return array_merge($this->rules, [
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($record?->getKey())->whereNull('deleted_at')],
        ]);
    }

    public function stockAttributeDefinitions(Product $product)
    {
        $attributes = $this->stockAttributePayload($product)->values();
        return response()->json(['data' => $attributes]);
    }

    public function updateAttributeValues(Request $request, Product $product)
    {
        $data = $request->validate([
            'attributes' => ['required', 'array'], 'attributes.*.product_attribute_id' => ['required', 'uuid', 'distinct'],
            'attributes.*.value' => ['present'],
        ]);
        $definitions = $this->applicableAttributes($product)->get()->keyBy('id')->reject(fn ($attribute) => $attribute->per_unit);
        $submitted = collect($data['attributes'])->keyBy('product_attribute_id');
        foreach ($data['attributes'] as $index => $entry) {
            $attribute = $definitions->get($entry['product_attribute_id']);
            if ($attribute === null) throw ValidationException::withMessages(['attributes' => 'Um ou mais atributos não estão disponíveis para este produto.']);
            if ($attribute->per_unit) throw ValidationException::withMessages(["attributes.{$index}.value" => 'Atributos por unidade devem ser informados na entrada de estoque.']);
            if ($attribute->required && $this->isEmptyAttributeValue($entry['value'])) {
                throw ValidationException::withMessages(["attributes.{$index}.value" => "O atributo {$attribute->name} é obrigatório."]);
            }
            $this->validateAttributeValue($attribute, $entry['value'], "attributes.{$index}.value");
        }
        foreach ($definitions as $id => $attribute) {
            if ($attribute->required && ! $submitted->has($id)) throw ValidationException::withMessages(['attributes' => "O atributo {$attribute->name} é obrigatório."]);
        }
        DB::transaction(function () use ($definitions, $submitted, $product) {
            foreach ($definitions as $id => $attribute) {
                $entry = $submitted->get($id);
                if ($entry === null) {
                    DB::table('product_attribute_values')->where('product_id', $product->id)->where('product_attribute_id', $id)->delete();
                    continue;
                }
                DB::table('product_attribute_values')->updateOrInsert(
                    ['product_id' => $product->id, 'product_attribute_id' => $id],
                    ['value' => json_encode($entry['value'], JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });
        return response()->json(['data' => $this->productAttributePayload($product)]);
    }

    public function attributeDefinitions(Product $product)
    {
        return response()->json(['data' => $this->productAttributePayload($product)]);
    }

    private function stockAttributePayload(Product $product)
    {
        $categoryIds = collect();
        $category = $product->categoryRelation;
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
                $query->where('entity_type', 'estoque')
                    ->orWhere(fn ($productAttributes) => $productAttributes->where('entity_type', 'produto')->where('differentiate_stock', true));
            })
            ->orderBy('name')
            ->get()
            ->map(fn (ProductAttribute $attribute) => [
                'product_attribute_id' => $attribute->id,
                'name' => $attribute->name,
                'entity_type' => $attribute->entity_type,
                'type' => $attribute->differentiate_stock && $attribute->type === 'selecao_multipla' ? 'selecao_unica' : $attribute->type,
                'options' => $attribute->options,
                'required' => (bool) $attribute->required,
                'differentiate_stock' => (bool) $attribute->differentiate_stock,
                'per_unit' => (bool) $attribute->per_unit,
                'value' => null,
            ]);
    }

    private function applicableAttributes(Product $product)
    {
        $categoryIds = collect();
        $category = $product->categoryRelation;
        while ($category) {
            $categoryIds->push($category->id);
            $category = $category->parent;
        }
        return ProductAttribute::query()->where('entity_type', 'produto')->where('active', true)
            ->where(function ($query) use ($categoryIds) {
                $query->whereDoesntHave('categories')
                    ->orWhereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds));
            });
    }

    private function productAttributePayload(Product $product)
    {
        $values = DB::table('product_attribute_values')->where('product_id', $product->id)->pluck('value', 'product_attribute_id');
        $applicable = $this->applicableAttributes($product)->orderBy('name')->get();
        return $applicable->map(fn (ProductAttribute $attribute) => [
            'product_attribute_id' => $attribute->id, 'name' => $attribute->name, 'type' => $attribute->type,
            'options' => $attribute->options, 'required' => (bool) $attribute->required,
            'differentiate_stock' => (bool) $attribute->differentiate_stock,
            'value' => $values->has($attribute->id) ? json_decode($values->get($attribute->id), true) : null,
        ]);
    }

    private function validateAttributeValue(ProductAttribute $attribute, mixed $value, string $errorKey): void
    {
        if ($this->isEmptyAttributeValue($value)) return;
        $valid = match ($attribute->type) {
            'texto' => is_string($value), 'numerico' => is_numeric($value), 'booleano' => is_bool($value),
            'selecao_unica' => is_string($value) && in_array($value, $attribute->options ?? [], true),
            'selecao_multipla' => is_array($value) && count(array_diff($value, $attribute->options ?? [])) === 0 && count($value) === count(array_unique($value)),
            default => false,
        };
        if (! $valid) throw ValidationException::withMessages([$errorKey => "O valor informado para {$attribute->name} não corresponde ao tipo ou às opções configuradas."]);
    }

    private function isEmptyAttributeValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
