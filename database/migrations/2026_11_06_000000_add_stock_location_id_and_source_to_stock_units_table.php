<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_units', function (Blueprint $table) {
            $table->uuid('stock_location_id')->nullable()->after('condition_id');
            $table->foreign('stock_location_id')->references('id')->on('stock_locations')->nullOnDelete();
            $table->string('source')->nullable()->after('stock_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_units', function (Blueprint $table) {
            $table->dropForeign(['stock_location_id']);
            $table->dropColumn(['stock_location_id', 'source']);
        });
    }
};

