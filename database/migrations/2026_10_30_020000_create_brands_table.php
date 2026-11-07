<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
        });

        DB::table('products')->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand')->each(function (string $name) {
            DB::table('brands')->insert([
                'id' => (string) str()->uuid(),
                'name' => $name,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
