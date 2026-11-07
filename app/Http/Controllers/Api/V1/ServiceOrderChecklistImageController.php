<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderChecklist;
use App\Services\ImageStorageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ServiceOrderChecklistImageController extends Controller
{
    public function __construct(
        protected ImageStorageService $imageStorageService
    ) {}

    public function index(ServiceOrderChecklist $checklist)
    {
        return response()->json(['data' => $checklist->images]);
    }

    public function store(Request $request, ServiceOrderChecklist $checklist)
    {
        $request->validate([
            'image' => ['required_without:file', 'file', 'image', 'max:10240'],
            'file' => ['required_without:image', 'file', 'image', 'max:10240'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('image') ?? $request->file('file');
        $isPrimary = $request->boolean('is_primary', false);

        $image = $this->imageStorageService->upload($checklist, $file, $isPrimary);

        return response()->json($image, 201);
    }

    public function destroy(ServiceOrderChecklist $checklist, Image $image)
    {
        if ($image->imageable_id !== $checklist->id || $image->imageable_type !== $checklist->getMorphClass()) {
            throw new NotFoundHttpException('Imagem não encontrada para este item de checklist.');
        }

        $this->imageStorageService->delete($image);

        return response()->noContent();
    }

    public function makePrimary(ServiceOrderChecklist $checklist, Image $image)
    {
        if ($image->imageable_id !== $checklist->id || $image->imageable_type !== $checklist->getMorphClass()) {
            throw new NotFoundHttpException('Imagem não encontrada para este item de checklist.');
        }

        $image = $this->imageStorageService->setPrimary($image);

        return response()->json($image);
    }

    public function indexForOrder(ServiceOrder $serviceOrder, ServiceOrderChecklist $checklist)
    {
        $this->ensureBelongsToOrder($serviceOrder, $checklist);

        return $this->index($checklist);
    }

    public function storeForOrder(Request $request, ServiceOrder $serviceOrder, ServiceOrderChecklist $checklist)
    {
        $this->ensureBelongsToOrder($serviceOrder, $checklist);

        return $this->store($request, $checklist);
    }

    public function destroyForOrder(ServiceOrder $serviceOrder, ServiceOrderChecklist $checklist, Image $image)
    {
        $this->ensureBelongsToOrder($serviceOrder, $checklist);

        return $this->destroy($checklist, $image);
    }

    public function makePrimaryForOrder(ServiceOrder $serviceOrder, ServiceOrderChecklist $checklist, Image $image)
    {
        $this->ensureBelongsToOrder($serviceOrder, $checklist);

        return $this->makePrimary($checklist, $image);
    }

    protected function ensureBelongsToOrder(ServiceOrder $serviceOrder, ServiceOrderChecklist $checklist): void
    {
        if ($checklist->service_order_id !== $serviceOrder->id) {
            throw new NotFoundHttpException('Item de checklist não pertence à ordem de serviço informada.');
        }
    }
}

