<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Services\ImageStorageService;

class ImageController extends Controller
{
    public function __construct(
        protected ImageStorageService $imageStorageService
    ) {}

    public function destroy(Image $image)
    {
        $this->imageStorageService->delete($image);

        return response()->noContent();
    }

    public function makePrimary(Image $image)
    {
        $image = $this->imageStorageService->setPrimary($image);

        return response()->json($image);
    }
}

