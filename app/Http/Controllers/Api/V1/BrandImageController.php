<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Image;
use App\Services\ImageStorageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BrandImageController extends Controller
{
    public function __construct(
        protected ImageStorageService $imageStorageService
    ) {}

    public function index(Brand $brand)
    {
        return response()->json(['data' => $brand->images]);
    }

    public function store(Request $request, Brand $brand)
    {
        $request->validate([
            'image' => ['required_without:file', 'file', 'image', 'max:10240'],
            'file' => ['required_without:image', 'file', 'image', 'max:10240'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('image') ?? $request->file('file');
        $isPrimary = $request->boolean('is_primary', false);

        $image = $this->imageStorageService->upload($brand, $file, $isPrimary);

        return response()->json($image, 201);
    }

    public function destroy(Brand $brand, Image $image)
    {
        if ($image->imageable_id !== $brand->id || $image->imageable_type !== $brand->getMorphClass()) {
            throw new NotFoundHttpException('Imagem não encontrada para esta marca.');
        }

        $this->imageStorageService->delete($image);

        return response()->noContent();
    }

    public function makePrimary(Brand $brand, Image $image)
    {
        if ($image->imageable_id !== $brand->id || $image->imageable_type !== $brand->getMorphClass()) {
            throw new NotFoundHttpException('Imagem não encontrada para esta marca.');
        }

        $image = $this->imageStorageService->setPrimary($image);

        return response()->json($image);
    }
}

