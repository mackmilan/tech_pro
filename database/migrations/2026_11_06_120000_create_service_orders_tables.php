<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->uuid('technician_id')->nullable();
            $table->foreign('technician_id')->references('id')->on('users')->nullOnDelete();

            $table->string('number');
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');

            // Device / Equipment
            $table->string('device_brand')->nullable();
            $table->string('device_model');
            $table->string('device_imei_serial')->nullable();
            $table->string('device_color')->nullable();
            $table->string('device_password')->nullable();
            $table->text('device_condition')->nullable();
            $table->text('accessories')->nullable();

            // Problem & Diagnosis
            $table->text('defect_reported');
            $table->text('defect_diagnosed')->nullable();
            $table->text('solution_applied')->nullable();
            $table->text('technical_notes')->nullable();

            // Dates
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('expected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Financials
            $table->decimal('services_amount', 12, 2)->default(0);
            $table->decimal('products_amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Notes and Terms
            $table->text('notes')->nullable();
            $table->text('warranty_terms')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index('customer_id');
            $table->index('technician_id');
        });

        DB::statement('CREATE UNIQUE INDEX service_orders_number_active_unique ON service_orders (number) WHERE deleted_at IS NULL');

        Schema::create('service_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_order_id');
            $table->foreign('service_order_id')->references('id')->on('service_orders')->cascadeOnDelete();
            $table->string('type')->default('service'); // 'service' | 'product'
            $table->uuid('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();

            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['service_order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_items');
        DB::statement('DROP INDEX IF EXISTS service_orders_number_active_unique');
        Schema::dropIfExists('service_orders');
    }
};

