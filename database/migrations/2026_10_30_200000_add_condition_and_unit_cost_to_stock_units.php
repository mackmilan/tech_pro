<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_units', function (Blueprint $table) {
            $table->uuid('condition_id')->nullable();
            $table->foreign('condition_id')->references('id')->on('product_conditions')->nullOnDelete();
            $table->decimal('unit_cost', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_units', function (Blueprint $table) {
            $table->dropForeign(['condition_id']);
            $table->dropColumn(['condition_id', 'unit_cost']);
        });
    }
};
