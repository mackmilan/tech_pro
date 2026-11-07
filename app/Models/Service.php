<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends BaseModel
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'price',
        'estimated_minutes',
        'warranty_days',
        'active',
    ];

    public function serviceOrderItems(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class);
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(ServiceChecklist::class);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'estimated_minutes' => 'integer',
            'warranty_days' => 'integer',
            'active' => 'boolean',
        ];
    }
}

