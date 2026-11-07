<?php

namespace Tests\Feature;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_default_matriz_branch_mapped_to_the_keycloak_organization(): void
    {
        $this->seed();

        $branch = Branch::query()->where('code', 'MATRIZ')->first();

        $this->assertNotNull($branch);
        $this->assertSame('Matriz', $branch->name);
        $this->assertSame('infocell-matriz', $branch->keycloak_organization_id);
        $this->assertTrue($branch->active);
        $this->assertNotNull($branch->stock);
        $this->assertSame($branch->id, $branch->stock->branch_id);
        $this->assertSame(1, \App\Models\Stock::query()->where('branch_id', $branch->id)->count());
    }

    public function test_seeding_twice_does_not_create_duplicate_matriz_branches(): void
    {
        $this->seed();
        Branch::query()->where('code', 'MATRIZ')->delete();

        $this->seed();

        $this->assertSame(1, Branch::query()->where('code', 'MATRIZ')->count());
    }
}
