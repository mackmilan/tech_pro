<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type', 30);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('differentiate_stock')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['type', 'name']);
        });

        Schema::create('category_product_attribute', function (Blueprint $table) {
            $table->uuid('category_id');
            $table->uuid('product_attribute_id');
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('product_attribute_id')->references('id')->on('product_attributes')->cascadeOnDelete();
            $table->primary(['category_id', 'product_attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product_attribute');
        Schema::dropIfExists('product_attributes');
    }
};
