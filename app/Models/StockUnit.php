<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockUnit extends BaseModel
{
    protected $fillable = ['stock_item_id', 'condition_id', 'stock_location_id', 'source', 'unit_cost', 'attributes', 'active'];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2', 'attributes' => 'array'];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(ProductCondition::class, 'condition_id');
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }
}
