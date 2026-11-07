<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::query();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return response()->json($query->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $this->validateService($request);

        $service = Service::create($data);

        return response()->json($service->refresh(), 201);
    }

    public function show(Service $service)
    {
        return response()->json($service);
    }

    public function update(Request $request, Service $service)
    {
        $data = $this->validateService($request, $service);

        $service->update($data);

        return response()->json($service->refresh());
    }

    public function destroy(Service $service)
    {
        if ($service->serviceOrderItems()->exists()) {
            throw ValidationException::withMessages([
                'service' => 'Não é possível excluir um serviço vinculado a ordens de serviço.',
            ]);
        }

        $service->delete();

        return response()->noContent();
    }

    protected function validateService(Request $request, ?Service $service = null): array
    {
        $codeRule = Rule::unique('services', 'code')->whereNull('deleted_at');
        if ($service) {
            $codeRule->ignore($service->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', $codeRule],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'],
            'warranty_days' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}

