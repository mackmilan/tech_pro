<?php

namespace App\Models;

class ServiceOrderStatusRule extends BaseModel
{
    protected $table = 'service_order_status_rules';

    protected $fillable = [
        'status',
        'name',
        'description',
        'color',
        'allow_view',
        'allow_edit',
        'allow_execute',
        'allow_cancel',
        'allow_print',
        'allowed_transitions',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'allow_view' => 'boolean',
            'allow_edit' => 'boolean',
            'allow_execute' => 'boolean',
            'allow_cancel' => 'boolean',
            'allow_print' => 'boolean',
            'allowed_transitions' => 'array',
            'active' => 'boolean',
        ];
    }

    public const STANDARD_STATUSES = [
        'open' => 'Aberta',
        'in_analysis' => 'Em análise',
        'approved' => 'Aprovada',
        'in_progress' => 'Em andamento',
        'waiting_parts' => 'Aguardando peças',
        'completed' => 'Concluída',
        'delivered' => 'Entregue',
        'cancelled' => 'Cancelada',
    ];

    public static function defaultRules(): array
    {
        return [
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
                'allowed_transitions' => ['in_analysis', 'approved', 'in_progress', 'cancelled'],
                'active' => true,
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
                'allowed_transitions' => ['approved', 'in_progress', 'waiting_parts', 'cancelled'],
                'active' => true,
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
                'allowed_transitions' => ['in_progress', 'waiting_parts', 'cancelled'],
                'active' => true,
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
                'allowed_transitions' => ['waiting_parts', 'completed', 'cancelled'],
                'active' => true,
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
                'allowed_transitions' => ['in_progress', 'completed', 'cancelled'],
                'active' => true,
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
                'allowed_transitions' => ['delivered'],
                'active' => true,
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
                'allowed_transitions' => [],
                'active' => true,
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
                'allowed_transitions' => [],
                'active' => true,
            ],
        ];
    }
}

