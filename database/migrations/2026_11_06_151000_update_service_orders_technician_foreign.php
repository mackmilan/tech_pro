<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_orders')->whereNotNull('technician_id')->update(['technician_id' => null]);

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['technician_id']);
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreign('technician_id')->references('id')->on('technicians')->nullOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS service_orders_number_active_unique');
            DB::statement('CREATE UNIQUE INDEX service_orders_number_active_unique ON service_orders (number) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        DB::table('service_orders')->whereNotNull('technician_id')->update(['technician_id' => null]);

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['technician_id']);
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreign('technician_id')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS service_orders_number_active_unique');
            DB::statement('CREATE UNIQUE INDEX service_orders_number_active_unique ON service_orders (number) WHERE deleted_at IS NULL');
        }
    }
};
