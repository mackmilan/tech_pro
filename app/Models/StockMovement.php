<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends BaseModel
{
    protected $fillable = ['stock_id', 'stock_location_id', 'product_id', 'type', 'source', 'quantity', 'condition_id', 'unit_cost', 'reference', 'notes'];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2'];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
