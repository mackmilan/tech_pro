<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductAttribute;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductAttributeController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'entity_type' => ['sometimes', 'string', Rule::in(ProductAttribute::ENTITY_TYPES)],
            'category_id' => ['sometimes', 'uuid', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'all' => ['sometimes', 'in:true,1'],
        ]);
        $query = ProductAttribute::query()->with('categories');
        if (isset($data['entity_type'])) $query->where('entity_type', $data['entity_type']);
        if (isset($data['category_id'])) {
            $categoryIds = collect();
            $category = Category::query()->find($data['category_id']);
            while ($category) {
                $categoryIds->push($category->id);
                $category = $category->parent;
            }
            $query->where(function ($attributes) use ($categoryIds) {
                $attributes->whereDoesntHave('categories')->orWhereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds));
            });
        }
        if ($request->boolean('all')) return response()->json(['data' => $query->orderBy('name')->get()]);
        return response()->json(['data' => $query->orderBy('name')->paginate(20)]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $categoryIds = $data['category_ids'];
        unset($data['category_ids']);
        $attribute = ProductAttribute::create($data);
        $attribute->categories()->sync($categoryIds);
        return response()->json($attribute->load('categories'), 201);
    }

    public function show(ProductAttribute $productAttribute)
    {
        return response()->json($productAttribute->load('categories'));
    }

    public function update(Request $request, ProductAttribute $productAttribute)
    {
        $data = $this->validatedData($request);
        $categoryIds = $data['category_ids'];
        unset($data['category_ids']);
        $productAttribute->update($data);
        $productAttribute->categories()->sync($categoryIds);
        return response()->json($productAttribute->refresh()->load('categories'));
    }

    public function destroy(ProductAttribute $productAttribute)
    {
        $productAttribute->delete();
        return response()->noContent();
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'entity_type' => ['required', 'string', Rule::in(ProductAttribute::ENTITY_TYPES)],
            'type' => ['required', 'string', Rule::in(ProductAttribute::TYPES)],
            'options' => ['present', 'array'], 'options.*' => ['required', 'string', 'max:255', 'distinct'],
            'required' => ['required', 'boolean'],
            'differentiate_stock' => ['sometimes', 'boolean'],
            'per_unit' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'], 'category_ids' => ['present', 'array'],
            'category_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('categories', 'id')->whereNull('deleted_at')],
        ]);
        $data['differentiate_stock'] = (bool) ($data['differentiate_stock'] ?? false);
        if ($data['per_unit'] ?? false) {
            if ($data['entity_type'] !== 'estoque') {
                throw ValidationException::withMessages(['per_unit' => 'O preenchimento por unidade só pode ser configurado em atributos de estoque.']);
            }
            if ($data['differentiate_stock']) {
                throw ValidationException::withMessages(['per_unit' => 'Um atributo por unidade não pode diferenciar variações de estoque.']);
            }
        } else {
            $data['per_unit'] = false;
        }
        $selectionType = in_array($data['type'], ['selecao_unica', 'selecao_multipla'], true);
        if ($selectionType && count($data['options']) === 0) throw ValidationException::withMessages(['options' => 'Informe pelo menos uma opção para este tipo de atributo.']);
        if (! $selectionType && count($data['options']) > 0) throw ValidationException::withMessages(['options' => 'Este tipo de atributo não aceita opções.']);
        $data['options'] = $selectionType ? array_values(array_map('trim', $data['options'])) : null;
        if ($selectionType && in_array('', $data['options'], true)) throw ValidationException::withMessages(['options' => 'As opções não podem ficar vazias.']);
        return $data;
    }
}
