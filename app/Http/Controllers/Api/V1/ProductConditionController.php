<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductCondition;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductConditionController extends Controller
{
    public function index()
    {
        return response()->json(['data' => ProductCondition::query()->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $productCondition = ProductCondition::create($this->validatedData($request));
        $productCondition->refresh();

        return response()->json($productCondition, 201);
    }

    public function show(ProductCondition $product_condition)
    {
        return response()->json($product_condition);
    }

    public function update(Request $request, ProductCondition $product_condition)
    {
        $product_condition->update($this->validatedData($request, $product_condition));

        return response()->json($product_condition->refresh());
    }

    public function destroy(ProductCondition $product_condition)
    {
        $product_condition->delete();

        return response()->noContent();
    }

    private function validatedData(Request $request, ?ProductCondition $productCondition = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('product_conditions', 'name')->ignore($productCondition?->id)->whereNull('deleted_at')],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
