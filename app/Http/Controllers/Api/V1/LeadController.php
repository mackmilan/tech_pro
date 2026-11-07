<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Lead;

class LeadController extends CrudController
{
    protected string $model = Lead::class;
    protected array $rules = ['customer_id' => 'nullable|uuid|exists:customers,id', 'title' => 'required|string|max:255', 'stage' => 'required|string|max:60', 'value' => 'required|numeric|min:0', 'source' => 'nullable|string|max:100', 'next_contact_at' => 'nullable|date'];
}
