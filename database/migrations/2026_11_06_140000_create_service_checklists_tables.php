<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_checklists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_id')->nullable();
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();

            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('stage')->default('both'); // 'entry', 'exit', 'both'
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);

            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('category');
            $table->index(['stage', 'active']);
            $table->index(['service_id', 'active']);
            $table->index('sort_order');
        });

        Schema::create('service_order_checklists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_order_id');
            $table->foreign('service_order_id')->references('id')->on('service_orders')->cascadeOnDelete();
            $table->uuid('service_checklist_id')->nullable();
            $table->foreign('service_checklist_id')->references('id')->on('service_checklists')->nullOnDelete();

            $table->string('item_name');
            $table->string('category')->nullable();
            $table->string('stage')->default('entry'); // 'entry', 'exit'
            $table->string('status')->default('not_tested'); // 'ok', 'defect', 'not_tested', 'not_applicable'
            $table->text('notes')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['service_order_id', 'stage']);
            $table->index('service_checklist_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_checklists');
        Schema::dropIfExists('service_checklists');
    }
};

