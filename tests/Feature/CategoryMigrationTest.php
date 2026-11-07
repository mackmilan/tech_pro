<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CategoryMigrationTest extends TestCase
{
    public function test_category_parent_foreign_key_references_uuid_primary_key(): void
    {
        $migration = require database_path('migrations/2026_10_30_013157_create_categories_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('categories', 'id'));
        $this->assertTrue(Schema::hasColumn('categories', 'parent_id'));
        $this->assertSame('varchar', Schema::getColumnType('categories', 'id'));
        $this->assertSame('varchar', Schema::getColumnType('categories', 'parent_id'));

        $foreignKeys = Schema::getForeignKeys('categories');

        $this->assertSame('categories', $foreignKeys[0]['foreign_table']);
        $this->assertSame(['parent_id'], $foreignKeys[0]['columns']);
        $this->assertSame(['id'], $foreignKeys[0]['foreign_columns']);
    }
}
