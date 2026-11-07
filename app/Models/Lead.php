<?php

namespace App\Models;

class Lead extends BaseModel
{
    protected $fillable = ['customer_id', 'title', 'stage', 'value', 'source', 'next_contact_at'];

    protected function casts(): array { return ['value' => 'decimal:2', 'next_contact_at' => 'datetime']; }

    public function customer() { return $this->belongsTo(Customer::class); }
}
