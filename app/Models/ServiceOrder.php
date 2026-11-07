<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceOrder extends BaseModel
{
    protected $fillable = [
        'branch_id',
        'customer_id',
        'technician_id',
        'number',
        'status',
        'priority',
        'device_brand',
        'device_model',
        'device_imei_serial',
        'device_color',
        'device_password',
        'device_condition',
        'accessories',
        'defect_reported',
        'defect_diagnosed',
        'solution_applied',
        'technical_notes',
        'opened_at',
        'expected_at',
        'completed_at',
        'delivered_at',
        'services_amount',
        'products_amount',
        'discount',
        'total',
        'notes',
        'warranty_terms',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'expected_at' => 'datetime',
            'completed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'services_amount' => 'decimal:2',
            'products_amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class);
    }

    public function serviceItems(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class)->where('type', 'service');
    }

    public function productItems(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class)->where('type', 'product');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(ServiceOrderChecklist::class);
    }
}

