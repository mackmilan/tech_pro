<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $uniqueIndexes = [
        ['branches', ['code'], 'branches_code_unique'],
        ['branches', ['keycloak_organization_id'], 'branches_keycloak_organization_id_unique'],
        ['products', ['sku'], 'products_sku_unique'],
        ['sales', ['number'], 'sales_number_unique'],
        ['users', ['email'], 'users_email_unique'],
        ['users', ['keycloak_id'], 'users_keycloak_id_unique'],
        ['personal_access_tokens', ['token'], 'personal_access_tokens_token_unique'],
        ['stocks', ['branch_id'], 'stocks_branch_id_unique'],
    ];

    private array $softDeleteUniqueIndexes = [
        ['brands', ['name'], 'brands_name_index'],
        ['product_conditions', ['name'], 'product_conditions_name_index'],
    ];

    public function up(): void
    {
        foreach ($this->uniqueIndexes as [$tableName, $columns, $oldIndex]) {
            Schema::table($tableName, function (Blueprint $table) use ($oldIndex) {
                $table->dropUnique($oldIndex);
            });

            $this->createActiveUniqueIndex($tableName, $columns);
        }

        Schema::table('stock_locations', function (Blueprint $table) {
            $table->dropUnique('stock_locations_stock_id_code_unique');
            $table->index(['stock_id', 'code']);
        });
        $this->createActiveUniqueIndex('stock_locations', ['stock_id', 'code']);

        foreach ($this->softDeleteUniqueIndexes as [$tableName, $columns, $oldIndex]) {
            Schema::table($tableName, function (Blueprint $table) use ($oldIndex) {
                $table->dropIndex($oldIndex);
            });

            $this->createActiveUniqueIndex($tableName, $columns);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->softDeleteUniqueIndexes) as [$tableName, $columns]) {
            $this->dropActiveUniqueIndex($tableName, $columns);
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                $table->index($columns);
            });
        }

        $this->dropActiveUniqueIndex('stock_locations', ['stock_id', 'code']);
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->dropIndex('stock_locations_stock_id_code_index');
            $table->unique(['stock_id', 'code']);
        });

        foreach (array_reverse($this->uniqueIndexes) as [$tableName, $columns]) {
            $this->dropActiveUniqueIndex($tableName, $columns);
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                $table->unique($columns);
            });
        }
    }

    private function createActiveUniqueIndex(string $tableName, array $columns): void
    {
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (%s) WHERE deleted_at IS NULL',
            $this->activeIndexName($tableName, $columns),
            $tableName,
            implode(', ', $columns),
        ));
    }

    private function dropActiveUniqueIndex(string $tableName, array $columns): void
    {
        DB::statement(sprintf('DROP INDEX IF EXISTS %s', $this->activeIndexName($tableName, $columns)));
    }

    private function activeIndexName(string $tableName, array $columns): string
    {
        return $tableName.'_'.implode('_', $columns).'_active_unique';
    }
};
