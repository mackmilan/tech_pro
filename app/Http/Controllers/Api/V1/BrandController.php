<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BrandController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Brand::query()->with('images')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $brand = Brand::create($this->validatedData($request));
        $brand->refresh()->load('images');

        return response()->json($brand, 201);
    }

    public function show(Brand $brand)
    {
        return response()->json($brand->load('images'));
    }

    public function update(Request $request, Brand $brand)
    {
        $brand->update($this->validatedData($request, $brand));

        return response()->json($brand->refresh()->load('images'));
    }

    public function destroy(Brand $brand)
    {
        if ($brand->products()->exists()) {
            throw ValidationException::withMessages([
                'brand' => 'Não é possível excluir uma marca vinculada a produtos.',
            ]);
        }

        $brand->delete();

        return response()->noContent();
    }

    private function validatedData(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('brands', 'name')->ignore($brand?->id)->whereNull('deleted_at')],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
