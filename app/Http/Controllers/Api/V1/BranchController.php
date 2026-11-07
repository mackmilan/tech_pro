<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\KeycloakOrganizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $query = Branch::query();
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        return response()->json($query->with('stock')->latest()->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $branch = new Branch($data);
        $branch->id = (string) Str::uuid();
        $keycloak = app(KeycloakOrganizationService::class);
        try {
            $organizationId = $keycloak->create($branch->name, 'branch-'.$branch->id, $branch->code, $branch->active);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Não foi possível criar a organização da filial no Keycloak.'], 502);
        }

        try {
            $branch->keycloak_organization_id = $organizationId;
            DB::transaction(fn () => $branch->save());

            return response()->json($branch->refresh()->load('stock'), 201);
        } catch (Throwable $exception) {
            try {
                $keycloak->delete($organizationId);
            } catch (Throwable) {
                report($exception);
            }

            throw $exception;
        }
    }

    public function show(string $branch)
    {
        return response()->json(Branch::query()->with('stock')->findOrFail($branch));
    }

    public function update(Request $request, string $branch)
    {
        $record = Branch::query()->findOrFail($branch);
        $data = $this->validatedData($request, $record);
        $keycloak = app(KeycloakOrganizationService::class);

        try {
            DB::transaction(function () use ($record, $data, $keycloak) {
                if (! $record->keycloak_organization_id) {
                    $record->keycloak_organization_id = $keycloak->create(
                        $record->name,
                        'branch-'.$record->id,
                        $record->code,
                        $record->active,
                    );
                    $record->save();
                }

                $keycloak->update($record->keycloak_organization_id, $data['name'], $data['code'], $data['active'] ?? $record->active);
                $record->update($data);
            });
        } catch (Throwable $exception) {
            return response()->json(['message' => 'Não foi possível atualizar a filial e sua organização no Keycloak.'], 502);
        }

        return response()->json($record->refresh());
    }

    public function destroy(string $branch)
    {
        $record = Branch::query()->findOrFail($branch);
        $keycloak = app(KeycloakOrganizationService::class);

        try {
            DB::transaction(function () use ($record, $keycloak) {
                $keycloak->delete($record->keycloak_organization_id);
                $record->stock()->firstOrFail()->delete();
                $record->delete();
            });
        } catch (Throwable $exception) {
            return response()->json(['message' => 'Não foi possível excluir a filial e sua organização no Keycloak.'], 502);
        }

        return response()->noContent();
    }

    private function validatedData(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', Rule::unique('branches', 'code')->ignore($branch?->id)->whereNull('deleted_at')],
            'document' => ['nullable', 'string', 'max:30'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
