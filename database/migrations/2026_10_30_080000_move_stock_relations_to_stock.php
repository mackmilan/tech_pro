<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (DB::table('branches')->orderBy('id')->get(['id']) as $branch) {
            $stockId = DB::table('stocks')->where('branch_id', $branch->id)->value('id');
            if (! $stockId) {
                $stockId = (string) Str::uuid();
                DB::table('stocks')->insert([
                    'id' => $stockId,
                    'branch_id' => $branch->id,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::table('stock_locations', function (Blueprint $table) {
            $table->uuid('stock_id')->nullable();
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('stock_id')->nullable();
        });

        foreach (DB::table('stock_locations')->orderBy('id')->get(['id', 'branch_id']) as $location) {
            $stockId = DB::table('stocks')->where('branch_id', $location->branch_id)->value('id');
            DB::table('stock_locations')->where('id', $location->id)->update(['stock_id' => $stockId]);
        }
        foreach (DB::table('stock_movements')->orderBy('id')->get(['id', 'branch_id']) as $movement) {
            $stockId = DB::table('stocks')->where('branch_id', $movement->branch_id)->value('id');
            DB::table('stock_movements')->where('id', $movement->id)->update(['stock_id' => $stockId]);
        }

        Schema::table('stock_locations', function (Blueprint $table) {
            $table->uuid('stock_id')->nullable(false)->change();
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('stock_id')->nullable(false)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['stock_location_id']);
        });
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->dropUnique('stock_locations_branch_id_code_unique');
            $table->dropColumn('branch_id');
            $table->foreign('stock_id')->references('id')->on('stocks')->cascadeOnDelete();
            $table->unique(['stock_id', 'code']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_branch_id_stock_location_id_product_id_index');
            $table->dropColumn('branch_id');
            $table->foreign('stock_id')->references('id')->on('stocks')->cascadeOnDelete();
            $table->foreign('stock_location_id')->references('id')->on('stock_locations')->cascadeOnDelete();
            $table->index(['stock_id', 'stock_location_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable();
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable();
        });

        foreach (DB::table('stock_locations')->orderBy('id')->get(['id', 'stock_id']) as $location) {
            $branchId = DB::table('stocks')->where('id', $location->stock_id)->value('branch_id');
            DB::table('stock_locations')->where('id', $location->id)->update(['branch_id' => $branchId]);
        }
        foreach (DB::table('stock_movements')->orderBy('id')->get(['id', 'stock_id']) as $movement) {
            $branchId = DB::table('stocks')->where('id', $movement->stock_id)->value('branch_id');
            DB::table('stock_movements')->where('id', $movement->id)->update(['branch_id' => $branchId]);
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['stock_location_id']);
            $table->dropForeign(['stock_id']);
        });
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->dropForeign(['stock_id']);
        });
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->dropUnique('stock_locations_stock_id_code_unique');
            $table->dropColumn('stock_id');
            $table->uuid('branch_id')->nullable(false)->change();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'code']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_stock_id_stock_location_id_product_id_index');
            $table->dropColumn('stock_id');
            $table->uuid('branch_id')->nullable(false)->change();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('stock_location_id')->references('id')->on('stock_locations')->cascadeOnDelete();
            $table->index(['branch_id', 'stock_location_id', 'product_id']);
        });
    }
};
