<?php

namespace App\Models;

class ProductCondition extends BaseModel
{
    protected $fillable = ['name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
