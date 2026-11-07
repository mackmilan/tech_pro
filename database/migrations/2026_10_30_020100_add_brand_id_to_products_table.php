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
            $table->uuid('brand_id')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
        });

        DB::table('products')->whereNotNull('brand')->orderBy('id')->get(['id', 'brand'])->each(function ($product) {
            $brandId = DB::table('brands')->where('name', $product->brand)->value('id');
            DB::table('products')->where('id', $product->id)->update(['brand_id' => $brandId]);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
        });
    }
};
