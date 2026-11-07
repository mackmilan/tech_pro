<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockItemAttributeValue extends BaseModel
{
    protected $fillable = ['stock_item_id', 'product_attribute_id', 'value', 'active'];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function productAttribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class);
    }

    protected function casts(): array
    {
        return ['value' => 'json', 'active' => 'boolean'];
    }
}
