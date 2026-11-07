<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrderStatusRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ServiceOrderStatusRuleController extends Controller
{
    public function index(Request $request)
    {
        $query = ServiceOrderStatusRule::query();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($sub) use ($search) {
                $sub->where('status', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('active') && $request->input('active') !== '' && $request->input('active') !== 'todos') {
            $query->where('active', $request->boolean('active'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->boolean('all')) {
            return response()->json([
                'data' => $query->orderBy('status')->get(),
            ]);
        }

        return response()->json($query->orderBy('status')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $this->validateRule($request);

        $rule = ServiceOrderStatusRule::create($data);

        return response()->json($rule, 201);
    }

    public function show(ServiceOrderStatusRule $serviceOrderStatusRule)
    {
        return response()->json($serviceOrderStatusRule);
    }

    public function update(Request $request, ServiceOrderStatusRule $serviceOrderStatusRule)
    {
        $data = $this->validateRule($request, $serviceOrderStatusRule);

        $serviceOrderStatusRule->update($data);

        return response()->json($serviceOrderStatusRule->fresh());
    }

    public function destroy(ServiceOrderStatusRule $serviceOrderStatusRule)
    {
        $serviceOrderStatusRule->delete();

        return response()->noContent();
    }

    public function seedDefaults()
    {
        $defaults = ServiceOrderStatusRule::defaultRules();

        DB::transaction(function () use ($defaults) {
            foreach ($defaults as $ruleData) {
                $existing = ServiceOrderStatusRule::withTrashed()->where('status', $ruleData['status'])->first();
                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->update($ruleData);
                } else {
                    ServiceOrderStatusRule::create($ruleData);
                }
            }
        });

        return response()->json([
            'message' => 'Regras de status restauradas com sucesso.',
            'data' => ServiceOrderStatusRule::orderBy('status')->get(),
        ]);
    }

    protected function validateRule(Request $request, ?ServiceOrderStatusRule $rule = null): array
    {
        $statusRule = Rule::unique('service_order_status_rules', 'status')->whereNull('deleted_at');
        if ($rule) {
            $statusRule->ignore($rule->id);
        }

        return $request->validate([
            'status' => [$rule ? 'sometimes' : 'required', 'string', 'max:50', $statusRule],
            'name' => [$rule ? 'sometimes' : 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'max:50'],
            'allow_view' => ['sometimes', 'boolean'],
            'allow_edit' => ['sometimes', 'boolean'],
            'allow_execute' => ['sometimes', 'boolean'],
            'allow_cancel' => ['sometimes', 'boolean'],
            'allow_print' => ['sometimes', 'boolean'],
            'allowed_transitions' => ['nullable', 'array'],
            'allowed_transitions.*' => ['string', 'max:50'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
