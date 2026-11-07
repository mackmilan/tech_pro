<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->boolean('per_unit')->default(false);
        });

        Schema::create('stock_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_item_id');
            $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
            $table->json('attributes');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['stock_item_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_units');
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropColumn('per_unit');
        });
    }
};
