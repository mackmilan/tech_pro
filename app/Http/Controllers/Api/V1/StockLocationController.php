<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Stock;
use App\Models\StockLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockLocationController extends CrudController
{
    protected string $model = StockLocation::class;
    protected array $rules = ['stock_id' => 'required|uuid|exists:stocks,id', 'name' => 'required|string|max:255', 'code' => 'required|string|max:30', 'type' => 'required|string|max:50'];

    public function index(Request $request)
    {
        $query = StockLocation::query()->with('stock.branch');
        if ($request->filled('stock_id')) $query->where('stock_id', $request->string('stock_id')->toString());
        if ($request->filled('search')) $query->where('name', 'like', '%'.$request->string('search').'%');

        return response()->json($query->latest()->paginate($request->integer('per_page', 20)));
    }

    public function show(Request $request)
    {
        $id = $request->route('stock_location');

        return response()->json(StockLocation::query()->with('stock.branch')->findOrFail($id));
    }

    protected function validationRules(Request $request, ?Model $record = null): array
    {
        return array_merge($this->rules, [
            'code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('stock_locations', 'code')
                    ->where('stock_id', $request->input('stock_id', $record?->stock_id))
                    ->whereNull('deleted_at')
                    ->ignore($record?->getKey()),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->validationRules($request));
        $location = Stock::findOrFail($data['stock_id'])
            ->locations()
            ->create(collect($data)->except('stock_id')->all());

        return response()->json($location, 201);
    }
}
