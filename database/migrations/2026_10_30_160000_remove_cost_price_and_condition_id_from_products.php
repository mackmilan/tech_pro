<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['condition_id']);
            $table->dropIndex(['condition_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'condition_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS products_sku_active_unique');
            DB::statement('DROP INDEX IF EXISTS products_sku_unique');
            DB::statement('CREATE UNIQUE INDEX products_sku_active_unique ON products (sku) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->uuid('condition_id')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('condition_id')->references('id')->on('product_conditions')->nullOnDelete();
            $table->index('condition_id');
        });
    }
};
