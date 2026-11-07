<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::withTrashed()->firstOrNew(['code' => 'MATRIZ']);

        if ($branch->exists && $branch->trashed()) {
            $branch->restore();
        }

        $branch->fill([
            'name' => 'Matriz',
            'keycloak_organization_id' => 'infocell-matriz',
            'active' => true,
            'document' => null,
        ]);
        $branch->save();
        $branch->stock()->firstOrCreate();
    }
}
