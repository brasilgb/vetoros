<?php

namespace App\Support;

final class OrderStatus
{
    public const OPEN = 1;
    public const CANCELLED = 2;
    public const BUDGET_GENERATED = 3;
    public const BUDGET_APPROVED = 4;
    public const BUDGET_REJECTED = 5;
    public const REPAIR_IN_PROGRESS = 6;
    public const SERVICE_COMPLETED = 7;
    public const SERVICE_NOT_EXECUTED = 8;
    public const CUSTOMER_NOTIFIED = 9;
    public const DELIVERED = 10;

    // 11 e 12 foram status de agendamento, aposentados em 2026_07_06 (remove_schedule_statuses_from_orders).
    // Não reutilizar: auditorias antigas ainda podem citá-los.
    public const IN_DIAGNOSIS = 13;
    public const AWAITING_PART = 14;
    public const AWAITING_CUSTOMER = 15;

    public const KIND_INITIAL = 'initial';
    public const KIND_FORWARD = 'forward';
    public const KIND_REGRESSION = 'regression';
    public const KIND_CORRECTION = 'correction';
    public const KIND_REOPEN = 'reopen';

    /**
     * Status encerrados: só saem por reabertura ou correção, sempre com motivo.
     */
    public const TERMINAL = [self::CANCELLED, self::DELIVERED];

    /**
     * Status de espera: podem ser alcançados a partir de qualquer etapa ativa e devolvem
     * a OS para qualquer etapa ativa sem configurar regressão.
     */
    public const WAITING = [self::AWAITING_PART, self::AWAITING_CUSTOMER];

    /**
     * Destinos que sempre exigem motivo.
     */
    public const REASON_REQUIRED = [self::CANCELLED, self::SERVICE_NOT_EXECUTED];

    /**
     * Posição no fluxo normal. Status de mesma posição são alternativas da mesma etapa.
     *
     * @var array<int, int>
     */
    private const RANK = [
        self::OPEN => 10,
        self::IN_DIAGNOSIS => 20,
        self::BUDGET_GENERATED => 30,
        self::BUDGET_APPROVED => 40,
        self::BUDGET_REJECTED => 40,
        self::REPAIR_IN_PROGRESS => 50,
        self::SERVICE_COMPLETED => 60,
        self::SERVICE_NOT_EXECUTED => 60,
        self::CUSTOMER_NOTIFIED => 70,
        self::DELIVERED => 80,
    ];

    /**
     * Trocas na mesma etapa tratadas como fluxo normal (decisão do cliente sobre o orçamento).
     *
     * @var list<array{int, int}>
     */
    private const SAME_STAGE_FORWARD = [
        [self::BUDGET_APPROVED, self::BUDGET_REJECTED],
        [self::BUDGET_REJECTED, self::BUDGET_APPROVED],
    ];

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return [
            self::OPEN => 'Ordem Aberta',
            self::IN_DIAGNOSIS => 'Em diagnóstico',
            self::CANCELLED => 'Ordem Cancelada',
            self::BUDGET_GENERATED => 'Orçamento Gerado',
            self::BUDGET_APPROVED => 'Orçamento Aprovado',
            self::BUDGET_REJECTED => 'Orçamento reprovado',
            self::AWAITING_CUSTOMER => 'Aguardando cliente',
            self::AWAITING_PART => 'Aguardando peça',
            self::REPAIR_IN_PROGRESS => 'Reparo em andamento',
            self::SERVICE_COMPLETED => 'Serviço concluído',
            self::SERVICE_NOT_EXECUTED => 'Serviço não executado',
            self::CUSTOMER_NOTIFIED => 'Cliente avisado / aguardando retirada',
            self::DELIVERED => 'Entregue ao cliente',
        ];
    }

    /**
     * @return list<int>
     */
    public static function values(): array
    {
        return array_keys(self::labels());
    }

    public static function label(int|string|null $status, string $fallback = 'Status atualizado'): string
    {
        if ($status === null || $status === '') {
            return $fallback;
        }

        return self::labels()[(int) $status] ?? $fallback;
    }

    public static function isValid(int|string|null $status): bool
    {
        return $status !== null && $status !== '' && in_array((int) $status, self::values(), true);
    }

    public static function isTerminal(int $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function isWaiting(int $status): bool
    {
        return in_array($status, self::WAITING, true);
    }

    /**
     * Classifica a transição pela matriz explícita.
     *
     * Retorna null quando a transição é proibida. Caso contrário, retorna o tipo
     * (forward, regression, correction, reopen) e se o motivo é obrigatório.
     *
     * $requestedKind só é considerado para transições que já exigem justificativa:
     * permite ao usuário declarar uma regressão como "correction" (lançamento errado)
     * e é obrigatório ("reopen" ou "correction") para sair de status encerrado.
     *
     * @return array{kind: string, reason_required: bool}|null
     */
    public static function classifyTransition(int $from, int $to, ?string $requestedKind = null): ?array
    {
        if (! self::isValid($from) || ! self::isValid($to) || $from === $to) {
            return null;
        }

        if (self::isTerminal($from)) {
            if (self::isTerminal($to) || ! in_array($requestedKind, [self::KIND_REOPEN, self::KIND_CORRECTION], true)) {
                return null;
            }

            return ['kind' => $requestedKind, 'reason_required' => true];
        }

        if (in_array($to, self::REASON_REQUIRED, true)) {
            return ['kind' => self::KIND_FORWARD, 'reason_required' => true];
        }

        if (
            self::isWaiting($to)
            || self::isWaiting($from)
            || self::RANK[$to] > self::RANK[$from]
            || in_array([$from, $to], self::SAME_STAGE_FORWARD, true)
        ) {
            return ['kind' => self::KIND_FORWARD, 'reason_required' => false];
        }

        return [
            'kind' => $requestedKind === self::KIND_CORRECTION ? self::KIND_CORRECTION : self::KIND_REGRESSION,
            'reason_required' => true,
        ];
    }

    public static function canTransition(int|string|null $from, int|string|null $to, ?string $requestedKind = null): bool
    {
        if (! self::isValid($to)) {
            return false;
        }

        if ($from === null || $from === '' || (int) $from === (int) $to) {
            return true;
        }

        return self::classifyTransition((int) $from, (int) $to, $requestedKind) !== null;
    }

    /**
     * Matriz completa para documentação e para o frontend.
     *
     * @return array<int, array<int, array{kind: string, reason_required: bool}|null>>
     */
    public static function matrix(): array
    {
        $matrix = [];

        foreach (self::values() as $from) {
            foreach (self::values() as $to) {
                if ($from === $to) {
                    continue;
                }

                $matrix[$from][$to] = self::isTerminal($from)
                    ? self::classifyTransition($from, $to, self::KIND_REOPEN)
                    : self::classifyTransition($from, $to);
            }
        }

        return $matrix;
    }
}
