<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TechnicianController extends Controller
{
    public function index(Request $request)
    {
        $query = Technician::query();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('document', 'like', "%{$search}%")
                    ->orWhere('specialty', 'like', "%{$search}%");
            });
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return response()->json($query->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $this->validateTechnician($request);

        $technician = Technician::create($data);

        return response()->json($technician->refresh(), 201);
    }

    public function show(Technician $technician)
    {
        return response()->json($technician);
    }

    public function update(Request $request, Technician $technician)
    {
        $data = $this->validateTechnician($request, $technician);

        $technician->update($data);

        return response()->json($technician->refresh());
    }

    public function destroy(Technician $technician)
    {
        if ($technician->serviceOrders()->exists()) {
            throw ValidationException::withMessages([
                'technician' => 'Não é possível excluir um técnico com ordens de serviço vinculadas.',
            ]);
        }

        $technician->delete();

        return response()->noContent();
    }

    protected function validateTechnician(Request $request, ?Technician $technician = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'document' => ['nullable', 'string', 'max:30'],
            'specialty' => ['nullable', 'string', 'max:255'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
        ]);
    }
}

