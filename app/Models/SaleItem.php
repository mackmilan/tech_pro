<?php

namespace App\Models;

class SaleItem extends BaseModel
{
    protected $fillable = ['product_id', 'stock_unit_id', 'quantity', 'unit_price', 'total'];

    public function stockUnit() { return $this->belongsTo(StockUnit::class); }

    protected function casts(): array { return ['unit_price' => 'decimal:2', 'total' => 'decimal:2']; }
}
