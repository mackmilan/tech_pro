<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Supplier;

class SupplierController extends CrudController
{
    protected string $model = Supplier::class;
    protected array $rules = ['name' => 'required|string|max:255', 'document' => 'nullable|string|max:30', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:30'];
}
