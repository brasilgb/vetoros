<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Aviso de assinatura exibido no app do tenant (VETOR-SAAS-EFI-04): perto do vencimento, vencendo
 * hoje ou em carência. Prazos calculados no servidor; a carência de 3 dias segue Tenant::subscriptionBucket.
 */
final class SubscriptionAlert
{
    /** Antecedência do aviso discreto, a mesma do e-mail "vence em 3 dias". */
    public const DUE_SOON_DAYS = 3;

    public const GRACE_DAYS = 3;

    /** @return array{status: string, expires_at: string, days_until_due: int, days_overdue: int, grace_days_left: int, message: string}|null */
    public static function for(Tenant $tenant): ?array
    {
        if (! $tenant->expires_at) {
            return null;
        }

        $today = now()->startOfDay();
        $expiresAt = $tenant->expires_at->copy()->startOfDay();
        $days = (int) $today->diffInDays($expiresAt, false);
        $bucket = $tenant->subscriptionBucket();

        $status = match (true) {
            $bucket === Tenant::SUBSCRIPTION_GRACE => 'grace_period',
            $bucket === Tenant::SUBSCRIPTION_ACTIVE && $days === 0 => 'due_today',
            $bucket === Tenant::SUBSCRIPTION_ACTIVE && $days > 0 && $days <= self::DUE_SOON_DAYS => 'due_soon',
            default => null,
        };

        if ($status === null) {
            return null;
        }

        $overdue = max(0, -$days);
        $graceLeft = max(0, self::GRACE_DAYS - $overdue);

        return [
            'status' => $status,
            'expires_at' => $expiresAt->toDateString(),
            'days_until_due' => max(0, $days),
            'days_overdue' => $overdue,
            'grace_days_left' => $graceLeft,
            'message' => match ($status) {
                'grace_period' => 'Sua assinatura venceu. Regularize para evitar bloqueio.',
                'due_today' => 'Sua assinatura vence hoje.',
                default => 'Sua assinatura vence em breve.',
            },
        ];
    }
}
