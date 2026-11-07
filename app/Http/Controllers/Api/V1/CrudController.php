<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

abstract class CrudController extends Controller
{
    protected string $model;
    protected array $rules = [];

    public function index(Request $request)
    {
        $query = ($this->model)::query();
        if (in_array($this->model, [\App\Models\StockLocation::class, \App\Models\StockMovement::class], true) && $request->filled('stock_id')) $query->where('stock_id', $request->string('stock_id')->toString());
        if ($request->filled('search')) $query->where('name', 'like', '%'.$request->string('search').'%');
        return response()->json($query->latest()->paginate($request->integer('per_page', 20)));
    }

    protected function validationRules(Request $request, ?Model $record = null): array
    {
        return $this->rules;
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->validationRules($request));
        $record = ($this->model)::create($data);
        return response()->json($this->loadRecord($record), 201);
    }

    public function show(Request $request)
    {
        return response()->json($this->loadRecord($this->recordFromRoute($request)));
    }

    protected function loadRecord(Model $record): Model
    {
        return $record;
    }

    public function update(Request $request)
    {
        $record = $this->recordFromRoute($request);
        $record->update($request->validate($this->validationRules($request, $record)));

        return response()->json($this->loadRecord($record->refresh()));
    }

    public function destroy(Request $request)
    {
        $this->recordFromRoute($request)->delete();

        return response()->noContent();
    }

    private function recordFromRoute(Request $request): Model
    {
        $id = $request->route()->parameters()[array_key_last($request->route()->parameters())];

        return ($this->model)::query()->findOrFail($id);
    }
}
