<?php

namespace App\Models;

class Sale extends BaseModel
{
    protected $fillable = ['branch_id', 'customer_id', 'number', 'status', 'subtotal', 'discount', 'total', 'payment_method', 'sold_at'];

    protected function casts(): array { return ['subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2', 'sold_at' => 'datetime']; }

    public function items() { return $this->hasMany(SaleItem::class); }
}
