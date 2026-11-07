<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\StockLocation;
use App\Services\StockEntryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::query()->with(['branch', 'supplier', 'items.product']);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->string('branch_id')->toString());
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->string('supplier_id')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return response()->json($query->latest()->paginate($request->integer('per_page', 20)));
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return response()->json($purchaseOrder->load(['branch', 'supplier', 'items.product']));
    }

    public function store(Request $request)
    {
        $data = $this->validateOrder($request);

        $number = ! empty($data['number']) ? $data['number'] : $this->generateOrderNumber();
        [$subtotal, $total, $preparedItems] = $this->calculateTotals($data);

        $purchaseOrder = DB::transaction(function () use ($data, $number, $subtotal, $total, $preparedItems) {
            $order = PurchaseOrder::create([
                'branch_id' => $data['branch_id'],
                'supplier_id' => $data['supplier_id'],
                'number' => $number,
                'status' => $data['status'] ?? 'draft',
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'subtotal' => $subtotal,
                'shipping_cost' => (float) ($data['shipping_cost'] ?? 0),
                'discount' => (float) ($data['discount'] ?? 0),
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($preparedItems as $item) {
                $order->items()->create($item);
            }

            return $order;
        });

        return response()->json($purchaseOrder->load(['branch', 'supplier', 'items.product']), 201);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            throw ValidationException::withMessages(['status' => 'Este pedido de compra já foi recebido e não pode ser alterado.']);
        }

        if ($purchaseOrder->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Este pedido de compra foi cancelado e não pode ser alterado.']);
        }

        $data = $this->validateOrder($request, $purchaseOrder);

        [$subtotal, $total, $preparedItems] = $this->calculateTotals($data);

        $purchaseOrder = DB::transaction(function () use ($purchaseOrder, $data, $subtotal, $total, $preparedItems) {
            $purchaseOrder->update([
                'branch_id' => $data['branch_id'],
                'supplier_id' => $data['supplier_id'],
                'number' => ! empty($data['number']) ? $data['number'] : $purchaseOrder->number,
                'status' => $data['status'] ?? $purchaseOrder->status,
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'subtotal' => $subtotal,
                'shipping_cost' => (float) ($data['shipping_cost'] ?? 0),
                'discount' => (float) ($data['discount'] ?? 0),
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            $purchaseOrder->items()->delete();
            foreach ($preparedItems as $item) {
                $purchaseOrder->items()->create($item);
            }

            return $purchaseOrder;
        });

        return response()->json($purchaseOrder->fresh()->load(['branch', 'supplier', 'items.product']));
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            throw ValidationException::withMessages(['status' => 'Este pedido de compra já foi recebido e não pode ser cancelado.']);
        }

        if ($purchaseOrder->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Este pedido de compra já está cancelado.']);
        }

        $purchaseOrder->update([
            'status' => 'cancelled',
        ]);

        return response()->json($purchaseOrder->fresh()->load(['branch', 'supplier', 'items.product']));
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            throw ValidationException::withMessages(['status' => 'Este pedido de compra já foi recebido.']);
        }

        if ($purchaseOrder->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Este pedido de compra foi cancelado e não pode ser recebido.']);
        }

        $stock = $purchaseOrder->branch->stock;
        if (! $stock) {
            throw ValidationException::withMessages(['branch' => 'A filial do pedido não possui um estoque associado.']);
        }

        $data = $request->validate([
            'stock_location_id' => ['sometimes', 'nullable', 'uuid', 'exists:stock_locations,id'],
            'condition_id' => ['sometimes', 'nullable', 'uuid', 'exists:product_conditions,id'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'uuid', 'exists:purchase_order_items,id'],
            'items.*.stock_location_id' => ['sometimes', 'nullable', 'uuid', 'exists:stock_locations,id'],
            'items.*.condition_id' => ['sometimes', 'nullable', 'uuid', 'exists:product_conditions,id'],
            'items.*.unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['sometimes', 'nullable', 'string'],
            'items.*.attributes' => ['sometimes', 'array'],
            'items.*.attributes.*.product_attribute_id' => ['required_with:items.*.attributes', 'uuid'],
            'items.*.attributes.*.value' => ['present_with:items.*.attributes'],
            'items.*.units' => ['sometimes', 'array'],
            'items.*.units.*.condition_id' => ['sometimes', 'nullable', 'uuid', 'exists:product_conditions,id'],
            'items.*.units.*.unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.units.*.stock_location_id' => ['sometimes', 'nullable', 'uuid', 'exists:stock_locations,id'],
            'items.*.units.*.attributes' => ['sometimes', 'array'],
            'items.*.units.*.attributes.*.product_attribute_id' => ['required_with:items.*.units.*.attributes', 'uuid'],
            'items.*.units.*.attributes.*.value' => ['present_with:items.*.units.*.attributes'],
        ]);

        $stockEntryService = app(StockEntryService::class);

        DB::transaction(function () use ($purchaseOrder, $stock, $data, $stockEntryService) {
            $submittedItems = collect($data['items'])->keyBy('purchase_order_item_id');

            foreach ($purchaseOrder->items as $orderItem) {
                $itemData = $submittedItems->get($orderItem->id);
                if (! $itemData) {
                    throw ValidationException::withMessages(['items' => "Dados de recebimento ausentes para o item {$orderItem->product->name}."]);
                }

                $locationId = $itemData['stock_location_id'] ?? $data['stock_location_id'] ?? null;
                if (! $locationId) {
                    throw ValidationException::withMessages(["items.{$orderItem->id}.stock_location_id" => "Informe a localização de estoque para {$orderItem->product->name}."]);
                }

                $location = StockLocation::whereKey($locationId)->where('stock_id', $stock->id)->first();
                if (! $location) {
                    throw ValidationException::withMessages(["items.{$orderItem->id}.stock_location_id" => 'A localização selecionada não pertence ao estoque da filial deste pedido.']);
                }

                $conditionId = $itemData['condition_id'] ?? $data['condition_id'] ?? null;
                $unitCost = $itemData['unit_cost'] ?? (float) $orderItem->unit_cost;

                $inboundData = [
                    'stock_location_id' => $locationId,
                    'product_id' => $orderItem->product_id,
                    'type' => 'in',
                    'quantity' => $orderItem->quantity,
                    'unit_cost' => $unitCost,
                    'condition_id' => $conditionId,
                    'source' => 'fornecedor',
                    'reference' => $purchaseOrder->number,
                    'notes' => $itemData['notes'] ?? $data['notes'] ?? "Recebimento do Pedido {$purchaseOrder->number}",
                ];

                if (isset($itemData['attributes'])) {
                    $inboundData['attributes'] = $itemData['attributes'];
                }

                if (isset($itemData['units'])) {
                    $inboundData['units'] = array_map(function ($unit) use ($locationId, $conditionId, $unitCost) {
                        return [
                            'condition_id' => $unit['condition_id'] ?? $conditionId,
                            'unit_cost' => $unit['unit_cost'] ?? $unitCost,
                            'stock_location_id' => $unit['stock_location_id'] ?? $locationId,
                            'source' => 'fornecedor',
                            'attributes' => $unit['attributes'] ?? [],
                        ];
                    }, $itemData['units']);
                }

                $stockEntryService->recordInbound($stock, $inboundData);
            }

            $purchaseOrder->update([
                'status' => 'received',
                'received_at' => now(),
            ]);
        });

        return response()->json($purchaseOrder->fresh()->load(['branch', 'supplier', 'items.product']));
    }

    protected function validateOrder(Request $request, ?PurchaseOrder $order = null): array
    {
        $numberRule = Rule::unique('purchase_orders', 'number')->whereNull('deleted_at');
        if ($order) {
            $numberRule->ignore($order->id);
        }

        return $request->validate([
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],
            'number' => ['sometimes', 'nullable', 'string', 'max:50', $numberRule],
            'status' => ['sometimes', 'string', 'in:draft,ordered,approved,received,cancelled'],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'shipping_cost' => ['sometimes', 'numeric', 'min:0'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);
    }

    protected function calculateTotals(array $data): array
    {
        $subtotal = 0;
        $preparedItems = [];

        foreach ($data['items'] as $item) {
            $quantity = (int) $item['quantity'];
            $unitCost = (float) $item['unit_cost'];
            $itemTotal = round($quantity * $unitCost, 2);
            $subtotal += $itemTotal;

            $preparedItems[] = [
                'product_id' => $item['product_id'],
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total' => $itemTotal,
                'notes' => $item['notes'] ?? null,
            ];
        }

        $shippingCost = (float) ($data['shipping_cost'] ?? 0);
        $discount = (float) ($data['discount'] ?? 0);

        if ($discount > $subtotal + $shippingCost) {
            throw ValidationException::withMessages(['discount' => 'Desconto não pode superar o valor total do pedido.']);
        }

        $total = round(max(0, $subtotal + $shippingCost - $discount), 2);

        return [$subtotal, $total, $preparedItems];
    }

    protected function generateOrderNumber(): string
    {
        do {
            $number = 'PC-'.date('Ymd').'-'.strtoupper(Str::random(4));
        } while (PurchaseOrder::where('number', $number)->exists());

        return $number;
    }
}
