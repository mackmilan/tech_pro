<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::query()->orderBy('name')->get();
        $tree = $this->buildTree($categories);

        return response()->json(['data' => $tree]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $category = Category::create($data);

        return response()->json($category->load('parent'), 201);
    }

    public function show(Category $category)
    {
        return response()->json($category->load(['parent', 'children']));
    }

    public function update(Request $request, Category $category)
    {
        $category->update($this->validatedData($request, $category));

        return response()->json($category->refresh()->load('parent'));
    }

    public function destroy(Category $category)
    {
        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Não é possível excluir uma categoria que possui subcategorias.',
            ]);
        }

        $category->delete();

        return response()->noContent();
    }

    private function validatedData(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')],
        ]);

        if ($category && isset($data['parent_id'])) {
            $this->ensureParentIsNotDescendant($category, $data['parent_id']);
        }

        return $data;
    }

    private function buildTree(Collection $categories, ?string $parentId = null): Collection
    {
        return $categories
            ->where('parent_id', $parentId)
            ->values()
            ->map(function (Category $category) use ($categories) {
                $category->setRelation('children', $this->buildTree($categories, $category->id));

                return $category;
            });
    }

    private function ensureParentIsNotDescendant(Category $category, string $parentId): void
    {
        $descendantIds = $this->descendantsOf($category)->modelKeys();

        if ($parentId === $category->getKey() || in_array($parentId, $descendantIds, true)) {
            throw ValidationException::withMessages([
                'parent_id' => 'A categoria não pode ser movida para dentro de si mesma ou de uma subcategoria.',
            ]);
        }
    }

    private function descendantsOf(Category $category): Collection
    {
        $descendants = new Collection;
        $children = $category->children()->get();

        foreach ($children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($this->descendantsOf($child));
        }

        return $descendants;
    }
}
