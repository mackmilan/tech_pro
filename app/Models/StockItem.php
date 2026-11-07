<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockItem extends BaseModel
{
    protected $fillable = ['stock_id', 'product_id', 'quantity'];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(StockItemAttributeValue::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    public function attributeValue(string $productAttributeId): ?StockItemAttributeValue
    {
        return $this->attributeValues->firstWhere('product_attribute_id', $productAttributeId);
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
