<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceOrderController extends Controller
{
    protected array $relations = [
        'branch',
        'customer',
        'technician',
        'items.product',
        'items.service',
        'checklists.serviceChecklist',
        'checklists.images',
    ];

    public function index(Request $request)
    {
        $query = ServiceOrder::query()->with($this->relations);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('number', 'like', "%{$search}%")
                    ->orWhere('device_model', 'like', "%{$search}%")
                    ->orWhere('device_brand', 'like', "%{$search}%")
                    ->orWhere('device_imei_serial', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('document', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->string('branch_id')->toString());
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->string('customer_id')->toString());
        }

        if ($request->filled('technician_id')) {
            $query->where('technician_id', $request->string('technician_id')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority')->toString());
        }

        if ($request->filled('date_from')) {
            $query->whereDate('opened_at', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('opened_at', '<=', $request->string('date_to')->toString());
        }

        return response()->json($query->latest('opened_at')->latest('id')->paginate($request->integer('per_page', 20)));
    }

    public function show(ServiceOrder $serviceOrder)
    {
        return response()->json($serviceOrder->load($this->relations));
    }

    public function store(Request $request)
    {
        $data = $this->validateOrder($request);

        $number = ! empty($data['number']) ? $data['number'] : $this->generateOrderNumber();
        [$servicesAmount, $productsAmount, $total, $preparedItems] = $this->calculateTotals($data);

        $status = $data['status'] ?? 'open';
        $completedAt = $data['completed_at'] ?? null;
        $deliveredAt = $data['delivered_at'] ?? null;

        if (in_array($status, ['completed', 'delivered'], true) && ! $completedAt) {
            $completedAt = now();
        }
        if ($status === 'delivered' && ! $deliveredAt) {
            $deliveredAt = now();
        }

        $serviceOrder = DB::transaction(function () use ($data, $number, $status, $servicesAmount, $productsAmount, $total, $completedAt, $deliveredAt, $preparedItems) {
            $order = ServiceOrder::create([
                'branch_id' => $data['branch_id'],
                'customer_id' => $data['customer_id'],
                'technician_id' => $data['technician_id'] ?? null,
                'number' => $number,
                'status' => $status,
                'priority' => $data['priority'] ?? 'normal',
                'device_brand' => $data['device_brand'] ?? null,
                'device_model' => $data['device_model'],
                'device_imei_serial' => $data['device_imei_serial'] ?? null,
                'device_color' => $data['device_color'] ?? null,
                'device_password' => $data['device_password'] ?? null,
                'device_condition' => $data['device_condition'] ?? null,
                'accessories' => $data['accessories'] ?? null,
                'defect_reported' => $data['defect_reported'],
                'defect_diagnosed' => $data['defect_diagnosed'] ?? null,
                'solution_applied' => $data['solution_applied'] ?? null,
                'technical_notes' => $data['technical_notes'] ?? null,
                'opened_at' => $data['opened_at'] ?? now(),
                'expected_at' => $data['expected_at'] ?? null,
                'completed_at' => $completedAt,
                'delivered_at' => $deliveredAt,
                'services_amount' => $servicesAmount,
                'products_amount' => $productsAmount,
                'discount' => (float) ($data['discount'] ?? 0),
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'warranty_terms' => $data['warranty_terms'] ?? 'Garantia legal de 90 dias conforme CDC sobre serviços e peças aplicadas.',
            ]);

            foreach ($preparedItems as $item) {
                $order->items()->create($item);
            }

            if (! empty($data['checklists'])) {
                foreach ($data['checklists'] as $chk) {
                    $order->checklists()->create([
                        'service_checklist_id' => $chk['service_checklist_id'] ?? null,
                        'item_name' => $chk['item_name'],
                        'category' => $chk['category'] ?? null,
                        'stage' => $chk['stage'] ?? 'entry',
                        'status' => $chk['status'] ?? 'not_tested',
                        'notes' => $chk['notes'] ?? null,
                        'verified_at' => ! empty($chk['status']) && $chk['status'] !== 'not_tested' ? ($chk['verified_at'] ?? now()) : null,
                    ]);
                }
            }

            return $order;
        });

        return response()->json($serviceOrder->load($this->relations), 201);
    }

    public function update(Request $request, ServiceOrder $serviceOrder)
    {
        if ($serviceOrder->status === 'delivered') {
            throw ValidationException::withMessages(['status' => 'Esta ordem de serviço já foi entregue e não pode ser alterada.']);
        }

        if ($serviceOrder->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Esta ordem de serviço foi cancelada e não pode ser alterada.']);
        }

        $data = $this->validateOrder($request, $serviceOrder);

        $status = $data['status'] ?? $serviceOrder->status;
        $completedAt = array_key_exists('completed_at', $data) ? $data['completed_at'] : $serviceOrder->completed_at;
        $deliveredAt = array_key_exists('delivered_at', $data) ? $data['delivered_at'] : $serviceOrder->delivered_at;

        if (in_array($status, ['completed', 'delivered'], true) && ! $completedAt) {
            $completedAt = now();
        }
        if ($status === 'delivered' && ! $deliveredAt) {
            $deliveredAt = now();
        }

        $itemsProvided = array_key_exists('items', $data);
        if ($itemsProvided) {
            [$servicesAmount, $productsAmount, $total, $preparedItems] = $this->calculateTotals($data);
        } else {
            $servicesAmount = $serviceOrder->services_amount;
            $productsAmount = $serviceOrder->products_amount;
            $discount = array_key_exists('discount', $data) ? (float) $data['discount'] : (float) $serviceOrder->discount;
            $total = round(max(0, (float) $servicesAmount + (float) $productsAmount - $discount), 2);
            $preparedItems = [];
        }

        $serviceOrder = DB::transaction(function () use ($serviceOrder, $data, $status, $completedAt, $deliveredAt, $servicesAmount, $productsAmount, $total, $itemsProvided, $preparedItems) {
            $serviceOrder->update([
                'branch_id' => $data['branch_id'],
                'customer_id' => $data['customer_id'],
                'technician_id' => array_key_exists('technician_id', $data) ? $data['technician_id'] : $serviceOrder->technician_id,
                'number' => ! empty($data['number']) ? $data['number'] : $serviceOrder->number,
                'status' => $status,
                'priority' => $data['priority'] ?? $serviceOrder->priority,
                'device_brand' => array_key_exists('device_brand', $data) ? $data['device_brand'] : $serviceOrder->device_brand,
                'device_model' => $data['device_model'],
                'device_imei_serial' => array_key_exists('device_imei_serial', $data) ? $data['device_imei_serial'] : $serviceOrder->device_imei_serial,
                'device_color' => array_key_exists('device_color', $data) ? $data['device_color'] : $serviceOrder->device_color,
                'device_password' => array_key_exists('device_password', $data) ? $data['device_password'] : $serviceOrder->device_password,
                'device_condition' => array_key_exists('device_condition', $data) ? $data['device_condition'] : $serviceOrder->device_condition,
                'accessories' => array_key_exists('accessories', $data) ? $data['accessories'] : $serviceOrder->accessories,
                'defect_reported' => $data['defect_reported'],
                'defect_diagnosed' => array_key_exists('defect_diagnosed', $data) ? $data['defect_diagnosed'] : $serviceOrder->defect_diagnosed,
                'solution_applied' => array_key_exists('solution_applied', $data) ? $data['solution_applied'] : $serviceOrder->solution_applied,
                'technical_notes' => array_key_exists('technical_notes', $data) ? $data['technical_notes'] : $serviceOrder->technical_notes,
                'opened_at' => $data['opened_at'] ?? $serviceOrder->opened_at,
                'expected_at' => array_key_exists('expected_at', $data) ? $data['expected_at'] : $serviceOrder->expected_at,
                'completed_at' => $completedAt,
                'delivered_at' => $deliveredAt,
                'services_amount' => $servicesAmount,
                'products_amount' => $productsAmount,
                'discount' => array_key_exists('discount', $data) ? (float) $data['discount'] : $serviceOrder->discount,
                'total' => $total,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $serviceOrder->notes,
                'warranty_terms' => array_key_exists('warranty_terms', $data) ? $data['warranty_terms'] : $serviceOrder->warranty_terms,
            ]);

            if ($itemsProvided) {
                $serviceOrder->items()->delete();
                foreach ($preparedItems as $item) {
                    $serviceOrder->items()->create($item);
                }
            }

            if (array_key_exists('checklists', $data)) {
                $this->syncChecklists($serviceOrder, $data['checklists'], 'entry');
            }

            return $serviceOrder;
        });

        return response()->json($serviceOrder->fresh()->load($this->relations));
    }

    public function cancel(ServiceOrder $serviceOrder)
    {
        if ($serviceOrder->status === 'delivered') {
            throw ValidationException::withMessages(['status' => 'Esta ordem de serviço já foi entregue e não pode ser cancelada.']);
        }

        if ($serviceOrder->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Esta ordem de serviço já está cancelada.']);
        }

        $serviceOrder->update([
            'status' => 'cancelled',
        ]);

        return response()->json($serviceOrder->fresh()->load($this->relations));
    }

    public function execute(Request $request, ServiceOrder $serviceOrder)
    {
        if ($serviceOrder->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Esta ordem de serviço foi cancelada e não pode ser executada.']);
        }

        $data = $request->validate([
            'technician_id' => ['nullable', 'uuid', 'exists:technicians,id'],
            'status' => ['required', 'string', 'in:open,in_analysis,approved,in_progress,waiting_parts,completed,delivered,cancelled'],
            'defect_diagnosed' => ['nullable', 'string'],
            'solution_applied' => ['nullable', 'string'],
            'technical_notes' => ['nullable', 'string'],
            'completed_at' => ['nullable', 'date'],
            'delivered_at' => ['nullable', 'date'],
            'checklists' => ['sometimes', 'array'],
            'checklists.*.id' => ['nullable', 'uuid'],
            'checklists.*.service_checklist_id' => ['nullable', 'uuid', 'exists:service_checklists,id'],
            'checklists.*.item_name' => ['required', 'string', 'max:255'],
            'checklists.*.category' => ['nullable', 'string', 'max:100'],
            'checklists.*.stage' => ['required', 'in:entry,exit'],
            'checklists.*.status' => ['required', 'in:ok,defect,not_tested,not_applicable'],
            'checklists.*.notes' => ['nullable', 'string'],
        ]);

        $status = $data['status'];
        $completedAt = array_key_exists('completed_at', $data) ? $data['completed_at'] : $serviceOrder->completed_at;
        $deliveredAt = array_key_exists('delivered_at', $data) ? $data['delivered_at'] : $serviceOrder->delivered_at;

        if (in_array($status, ['completed', 'delivered'], true) && ! $completedAt) {
            $completedAt = now();
        }
        if ($status === 'delivered' && ! $deliveredAt) {
            $deliveredAt = now();
        }

        $serviceOrder = DB::transaction(function () use ($serviceOrder, $data, $status, $completedAt, $deliveredAt) {
            $serviceOrder->update([
                'technician_id' => array_key_exists('technician_id', $data) ? $data['technician_id'] : $serviceOrder->technician_id,
                'status' => $status,
                'defect_diagnosed' => array_key_exists('defect_diagnosed', $data) ? $data['defect_diagnosed'] : $serviceOrder->defect_diagnosed,
                'solution_applied' => array_key_exists('solution_applied', $data) ? $data['solution_applied'] : $serviceOrder->solution_applied,
                'technical_notes' => array_key_exists('technical_notes', $data) ? $data['technical_notes'] : $serviceOrder->technical_notes,
                'completed_at' => $completedAt,
                'delivered_at' => $deliveredAt,
            ]);

            if (array_key_exists('checklists', $data)) {
                $this->syncChecklists($serviceOrder, $data['checklists'], 'exit');
            }

            return $serviceOrder;
        });

        return response()->json($serviceOrder->fresh()->load($this->relations));
    }

    protected function validateOrder(Request $request, ?ServiceOrder $order = null): array
    {
        $numberRule = Rule::unique('service_orders', 'number')->whereNull('deleted_at');
        if ($order) {
            $numberRule->ignore($order->id);
        }

        return $request->validate([
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'technician_id' => ['nullable', 'uuid', 'exists:technicians,id'],
            'number' => ['sometimes', 'nullable', 'string', 'max:50', $numberRule],
            'status' => ['sometimes', 'string', 'in:open,in_analysis,approved,in_progress,waiting_parts,completed,delivered,cancelled'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'device_brand' => ['nullable', 'string', 'max:100'],
            'device_model' => ['required', 'string', 'max:150'],
            'device_imei_serial' => ['nullable', 'string', 'max:100'],
            'device_color' => ['nullable', 'string', 'max:50'],
            'device_password' => ['nullable', 'string', 'max:100'],
            'device_condition' => ['nullable', 'string'],
            'accessories' => ['nullable', 'string'],
            'defect_reported' => ['required', 'string'],
            'defect_diagnosed' => ['nullable', 'string'],
            'solution_applied' => ['nullable', 'string'],
            'technical_notes' => ['nullable', 'string'],
            'opened_at' => ['sometimes', 'nullable', 'date'],
            'expected_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'delivered_at' => ['nullable', 'date'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'warranty_terms' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.type' => ['required_with:items', 'in:service,product'],
            'items.*.product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'items.*.service_id' => ['nullable', 'uuid', 'exists:services,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.discount' => ['sometimes', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
            'checklists' => ['sometimes', 'array'],
            'checklists.*.id' => ['nullable', 'uuid'],
            'checklists.*.service_checklist_id' => ['nullable', 'uuid', 'exists:service_checklists,id'],
            'checklists.*.item_name' => ['required_with:checklists', 'string', 'max:255'],
            'checklists.*.category' => ['nullable', 'string', 'max:100'],
            'checklists.*.stage' => ['required_with:checklists', 'in:entry,exit'],
            'checklists.*.status' => ['required_with:checklists', 'in:ok,defect,not_tested,not_applicable'],
            'checklists.*.notes' => ['nullable', 'string'],
            'checklists.*.verified_at' => ['nullable', 'date'],
        ]);
    }

    protected function calculateTotals(array $data): array
    {
        $servicesAmount = 0;
        $productsAmount = 0;
        $preparedItems = [];

        foreach ($data['items'] ?? [] as $item) {
            $quantity = (int) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $itemDiscount = (float) ($item['discount'] ?? 0);
            $itemTotal = round(max(0, ($quantity * $unitPrice) - $itemDiscount), 2);

            $type = $item['type'] ?? 'service';
            if ($type === 'service') {
                $servicesAmount += $itemTotal;
            } else {
                $productsAmount += $itemTotal;
            }

            $preparedItems[] = [
                'type' => $type,
                'product_id' => $item['product_id'] ?? null,
                'service_id' => $item['service_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $itemDiscount,
                'total' => $itemTotal,
                'notes' => $item['notes'] ?? null,
            ];
        }

        $globalDiscount = (float) ($data['discount'] ?? 0);
        $grossTotal = $servicesAmount + $productsAmount;

        if ($globalDiscount > $grossTotal) {
            throw ValidationException::withMessages(['discount' => 'O desconto geral não pode superar o valor total dos itens.']);
        }

        $total = round(max(0, $grossTotal - $globalDiscount), 2);

        return [$servicesAmount, $productsAmount, $total, $preparedItems];
    }

    protected function generateOrderNumber(): string
    {
        do {
            $number = 'OS-'.date('Ymd').'-'.strtoupper(Str::random(4));
        } while (ServiceOrder::where('number', $number)->exists());

        return $number;
    }

    protected function syncChecklists(ServiceOrder $serviceOrder, array $checklists, string $defaultStage = 'entry'): void
    {
        $existingChecklists = $serviceOrder->checklists()->get()->keyBy('id');
        $incomingIds = [];

        foreach ($checklists as $chk) {
            $chkId = $chk['id'] ?? null;
            $payload = [
                'service_checklist_id' => $chk['service_checklist_id'] ?? null,
                'item_name' => $chk['item_name'],
                'category' => $chk['category'] ?? null,
                'stage' => $chk['stage'] ?? $defaultStage,
                'status' => $chk['status'] ?? 'not_tested',
                'notes' => $chk['notes'] ?? null,
                'verified_at' => ! empty($chk['status']) && $chk['status'] !== 'not_tested' ? ($chk['verified_at'] ?? now()) : null,
            ];

            if ($chkId && $existingChecklists->has($chkId)) {
                $existing = $existingChecklists->get($chkId);
                $existing->update($payload);
                $incomingIds[] = $chkId;
            } else {
                $created = $serviceOrder->checklists()->create($payload);
                $incomingIds[] = $created->id;
            }
        }

        foreach ($existingChecklists as $id => $existing) {
            if (! in_array($id, $incomingIds, true)) {
                $existing->delete();
            }
        }
    }
}
