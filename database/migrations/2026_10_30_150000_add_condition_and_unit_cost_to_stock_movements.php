<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('condition_id')->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('condition_id')->references('id')->on('product_conditions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['condition_id']);
            $table->dropColumn(['condition_id', 'unit_cost']);
        });
    }
};