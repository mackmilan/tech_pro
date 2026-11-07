<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('imageable_type');
            $table->uuid('imageable_id');
            $table->string('disk')->default('s3');
            $table->string('bucket')->nullable();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('url')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['imageable_type', 'imageable_id']);
            $table->index(['imageable_type', 'imageable_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};

