<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceChecklist;
use Illuminate\Http\Request;

class ServiceChecklistController extends Controller
{
    public function index(Request $request)
    {
        $query = ServiceChecklist::query()->with('service');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('stage')) {
            $stage = $request->string('stage')->toString();
            if ($stage === 'entry') {
                $query->whereIn('stage', ['entry', 'both']);
            } elseif ($stage === 'exit') {
                $query->whereIn('stage', ['exit', 'both']);
            } else {
                $query->where('stage', $stage);
            }
        }

        if ($request->has('service_id')) {
            $serviceId = $request->input('service_id');
            if ($serviceId === null || $serviceId === '' || $serviceId === 'null') {
                $query->whereNull('service_id');
            } else {
                $query->where('service_id', $serviceId);
            }
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        return response()->json(
            $query->orderBy('sort_order')
                ->orderBy('name')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function store(Request $request)
    {
        $data = $this->validateChecklist($request);

        $checklist = ServiceChecklist::create($data);

        return response()->json($checklist->fresh()->load('service'), 201);
    }

    public function show(ServiceChecklist $serviceChecklist)
    {
        return response()->json($serviceChecklist->load('service'));
    }

    public function update(Request $request, ServiceChecklist $serviceChecklist)
    {
        $data = $this->validateChecklist($request, $serviceChecklist);

        $serviceChecklist->update($data);

        return response()->json($serviceChecklist->fresh()->load('service'));
    }

    public function destroy(ServiceChecklist $serviceChecklist)
    {
        $serviceChecklist->delete();

        return response()->noContent();
    }

    protected function validateChecklist(Request $request, ?ServiceChecklist $serviceChecklist = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'service_id' => ['nullable', 'uuid', 'exists:services,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'stage' => ['required', 'string', 'in:entry,exit,both'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}

