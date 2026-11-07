<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLocation extends BaseModel
{
    protected $fillable = ['stock_id', 'name', 'code', 'type'];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
