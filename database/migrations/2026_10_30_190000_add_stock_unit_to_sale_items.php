<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->uuid('stock_unit_id')->nullable();
            $table->foreign('stock_unit_id')->references('id')->on('stock_units')->restrictOnDelete();
            $table->unique('stock_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropUnique(['stock_unit_id']);
            $table->dropForeign(['stock_unit_id']);
            $table->dropColumn('stock_unit_id');
        });
    }
};
