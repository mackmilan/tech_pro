<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->uuid('condition_id')->nullable();
            $table->foreign('condition_id')->references('id')->on('product_conditions')->nullOnDelete();
            $table->index('condition_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['condition_id']);
            $table->dropIndex(['condition_id']);
            $table->dropColumn('condition_id');
        });

        Schema::dropIfExists('product_conditions');
    }
};
