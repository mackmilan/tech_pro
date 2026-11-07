<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Models\Product;
use App\Services\ImageStorageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductImageController extends Controller
{
    public function __construct(
        protected ImageStorageService $imageStorageService
    ) {}

    public function index(Product $product)
    {
        return response()->json(['data' => $product->images]);
    }

    public function store(Request $request, Product $product)
    {
        $request->validate([
            'image' => ['required_without:file', 'file', 'image', 'max:10240'],
            'file' => ['required_without:image', 'file', 'image', 'max:10240'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('image') ?? $request->file('file');
        $isPrimary = $request->boolean('is_primary', false);

        $image = $this->imageStorageService->upload($product, $file, $isPrimary);

        return response()->json($image, 201);
    }

    public function destroy(Product $product, Image $image)
    {
        if ($image->imageable_id !== $product->id || $image->imageable_type !== $product->getMorphClass()) {
            throw new NotFoundHttpException('Imagem não encontrada para este produto.');
        }

        $this->imageStorageService->delete($image);

        return response()->noContent();
    }

    public function makePrimary(Product $product, Image $image)
    {
        if ($image->imageable_id !== $product->id || $image->imageable_type !== $product->getMorphClass()) {
            throw new NotFoundHttpException('Imagem não encontrada para este produto.');
        }

        $image = $this->imageStorageService->setPrimary($image);

        return response()->json($image);
    }
}

