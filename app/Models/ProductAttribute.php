<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductAttribute extends BaseModel
{
    public const TYPES = ['texto', 'numerico', 'selecao_unica', 'selecao_multipla', 'booleano'];
    public const ENTITY_TYPES = ['produto', 'estoque'];

    protected $attributes = ['active' => true, 'per_unit' => false];

    protected $fillable = ['name', 'entity_type', 'type', 'options', 'required', 'differentiate_stock', 'per_unit', 'active'];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product_attribute');
    }

    public function stockItemValues(): HasMany
    {
        return $this->hasMany(StockItemAttributeValue::class);
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'differentiate_stock' => 'boolean',
            'per_unit' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
