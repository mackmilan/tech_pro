<?php

namespace App\Models;

use App\Models\Concerns\HasImages;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderChecklist extends BaseModel
{
    use HasImages;

    protected $fillable = [
        'service_order_id',
        'service_checklist_id',
        'item_name',
        'category',
        'stage',
        'status',
        'notes',
        'verified_at',
        'active',
    ];

    protected static function booted(): void
    {
        static::deleting(function (ServiceOrderChecklist $checklist) {
            if ($checklist->isForceDeleting()) {
                foreach ($checklist->images()->withTrashed()->get() as $image) {
                    $image->deleteFromDisk();
                    $image->forceDelete();
                }
            } else {
                foreach ($checklist->images as $image) {
                    $image->deleteFromDisk();
                    $image->delete();
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function serviceChecklist(): BelongsTo
    {
        return $this->belongsTo(ServiceChecklist::class);
    }
}

