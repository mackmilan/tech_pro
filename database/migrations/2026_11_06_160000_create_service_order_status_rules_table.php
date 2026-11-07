<?php

use App\Models\ServiceOrderStatusRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_status_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('color', 50)->nullable()->default('slate');

            // Action visibility permissions
            $table->boolean('allow_view')->default(true);
            $table->boolean('allow_edit')->default(true);
            $table->boolean('allow_execute')->default(true);
            $table->boolean('allow_cancel')->default(true);
            $table->boolean('allow_print')->default(true);

            // Allowed state machine transitions (next statuses)
            $table->json('allowed_transitions')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'status']);
        });

        DB::statement('CREATE UNIQUE INDEX service_order_status_rules_status_active_unique ON service_order_status_rules (status) WHERE deleted_at IS NULL');

        // Seed default state machine rules for the standard OS statuses
        $defaults = [
            [
                'status' => 'open',
                'name' => 'Aberta',
                'description' => 'Ordem de serviço cadastrada no sistema.',
                'color' => 'slate',
                'allow_view' => true,
                'allow_edit' => true,
                'allow_execute' => true,
                'allow_cancel' => true,
                'allow_print' => true,
                'allowed_transitions' => json_encode(['in_analysis', 'approved', 'in_progress', 'cancelled']),
            ],
            [
                'status' => 'in_analysis',
                'name' => 'Em análise',
                'description' => 'Aparelho em diagnóstico e avaliação técnica.',
                'color' => 'amber',
                'allow_view' => true,
                'allow_edit' => true,
                'allow_execute' => true,
                'allow_cancel' => true,
                'allow_print' => true,
                'allowed_transitions' => json_encode(['approved', 'in_progress', 'waiting_parts', 'cancelled']),
            ],
            [
                'status' => 'approved',
                'name' => 'Aprovada',
                'description' => 'Orçamento aprovado pelo cliente para início dos serviços.',
                'color' => 'blue',
                'allow_view' => true,
                'allow_edit' => true,
                'allow_execute' => true,
                'allow_cancel' => true,
                'allow_print' => true,
                'allowed_transitions' => json_encode(['in_progress', 'waiting_parts', 'cancelled']),
            ],
            [
                'status' => 'in_progress',
                'name' => 'Em andamento',
                'description' => 'Técnico executando o reparo ou serviço no aparelho.',
                'color' => 'indigo',
                'allow_view' => true,
                'allow_edit' => true,
                'allow_execute' => true,
                'allow_cancel' => true,
                'allow_print' => true,
                'allowed_transitions' => json_encode(['waiting_parts', 'completed', 'cancelled']),
            ],
            [
                'status' => 'waiting_parts',
                'name' => 'Aguardando peças',
                'description' => 'Serviço pausado aguardando recebimento de peças de reposição.',
                'color' => 'orange',
                'allow_view' => true,
                'allow_edit' => true,
                'allow_execute' => true,
                'allow_cancel' => true,
                'allow_print' => true,
                'allowed_transitions' => json_encode(['in_progress', 'completed', 'cancelled']),
            ],
            [
                'status' => 'completed',
                'name' => 'Concluída',
                'description' => 'Reparo finalizado com sucesso; aguardando retirada pelo cliente.',
                'color' => 'emerald',
                'allow_view' => true,
                'allow_edit' => false,
                'allow_execute' => false,
                'allow_cancel' => false,
                'allow_print' => true,
                'allowed_transitions' => json_encode(['delivered']),
            ],
            [
                'status' => 'delivered',
                'name' => 'Entregue',
                'description' => 'Aparelho entregue ao cliente e ordem encerrada.',
                'color' => 'green',
                'allow_view' => true,
                'allow_edit' => false,
                'allow_execute' => false,
                'allow_cancel' => false,
                'allow_print' => true,
                'allowed_transitions' => json_encode([]),
            ],
            [
                'status' => 'cancelled',
                'name' => 'Cancelada',
                'description' => 'Ordem de serviço cancelada ou não aprovada.',
                'color' => 'red',
                'allow_view' => true,
                'allow_edit' => false,
                'allow_execute' => false,
                'allow_cancel' => false,
                'allow_print' => true,
                'allowed_transitions' => json_encode([]),
            ],
        ];

        $now = now();
        foreach ($defaults as $rule) {
            DB::table('service_order_status_rules')->insert(array_merge($rule, [
                'id' => (string) Str::uuid(),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS service_order_status_rules_status_active_unique');
        Schema::dropIfExists('service_order_status_rules');
    }
};

