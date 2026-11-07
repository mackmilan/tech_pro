<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceChecklist extends BaseModel
{
    protected $fillable = [
        'service_id',
        'name',
        'category',
        'description',
        'stage',
        'is_required',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function orderChecklists(): HasMany
    {
        return $this->hasMany(ServiceOrderChecklist::class);
    }
}

