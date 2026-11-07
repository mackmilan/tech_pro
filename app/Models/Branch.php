<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Branch extends BaseModel
{
    protected $fillable = ['name', 'code', 'document', 'active', 'keycloak_organization_id'];

    protected function casts(): array { return ['active' => 'boolean']; }

    protected static function booted(): void
    {
        static::created(function (Branch $branch) {
            $branch->stock()->create();
        });
    }

    public function stockLocations(): HasManyThrough
    {
        return $this->hasManyThrough(StockLocation::class, Stock::class);
    }

    public function stock(): HasOne
    {
        return $this->hasOne(Stock::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }
}
