<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropUnique('stock_items_stock_id_product_id_unique');
            $table->index(['stock_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropIndex(['stock_id', 'product_id']);
            $table->unique(['stock_id', 'product_id']);
        });
    }
};