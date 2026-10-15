<?php

namespace App\Models\App;

use App\Support\TenantMailConfig;
use App\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Other extends Model
{
    use HasFactory, Tenantable;

    public const DEFAULT_RECORDS_PER_PAGE = 20;

    public const ALLOWED_RECORDS_PER_PAGE = [10, 20, 35, 50, 100];

    protected static function booted(): void
    {
        // A configuração de e-mail lida na requisição não pode sobreviver a uma gravação.
        static::saved(fn () => TenantMailConfig::forget());
        static::deleted(fn () => TenantMailConfig::forget());
    }

    protected $casts = [
        'navigation' => 'boolean',
        'enableparts' => 'boolean',
        'enable_finance' => 'boolean',
        'enablesales' => 'boolean',
        'enable_purchases' => 'boolean',
        'show_follow_ups_menu' => 'boolean',
        'show_tasks_menu' => 'boolean',
        'show_commercial_performance_menu' => 'boolean',
        'show_quality_menu' => 'boolean',
        'print_label_button_after_order_create' => 'boolean',
        'public_order_access_key_required' => 'boolean',
        'warranty_return_alert_threshold' => 'float',
        'communication_follow_up_cooldown_days' => 'integer',
        'automatic_follow_ups_enabled' => 'boolean',
        'enable_technician_schedule_notifications' => 'boolean',
        'customer_feedback_request_delay_days' => 'integer',
        'budget_conversion_target' => 'float',
        'payment_recovery_target' => 'float',
        'records_per_page' => 'integer',
    ];

    public static function recordsPerPage(?int $tenantId = null): int
    {
        $query = static::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $configured = (int) ($query->value('records_per_page') ?? self::DEFAULT_RECORDS_PER_PAGE);

        return in_array($configured, self::ALLOWED_RECORDS_PER_PAGE, true)
            ? $configured
            : self::DEFAULT_RECORDS_PER_PAGE;
    }

    public static function warrantyReturnAlertThreshold(): float
    {
        $configured = static::query()->value('warranty_return_alert_threshold');

        if ($configured !== null) {
            return (float) $configured;
        }

        return (float) config('business-metrics.warranty_return_alert_threshold', 10);
    }

    public static function communicationFollowUpCooldownDays(?int $tenantId = null): int
    {
        $query = static::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $configured = $query->value('communication_follow_up_cooldown_days');

        if ($configured !== null) {
            return max(1, (int) $configured);
        }

        return 2;
    }

    public static function automaticFollowUpsEnabled(?int $tenantId = null): bool
    {
        $query = static::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $configured = $query->value('automatic_follow_ups_enabled');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return false;
    }

    public static function technicianScheduleNotificationsEnabled(?int $tenantId = null): bool
    {
        $query = static::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return (bool) ($query->value('enable_technician_schedule_notifications') ?? false);
    }

    public static function financeEnabled(?int $tenantId = null): bool
    {
        $query = static::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return (bool) ($query->value('enable_finance') ?? false);
    }

    public static function purchasesEnabled(?int $tenantId = null): bool
    {
        $query = static::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return (bool) ($query->value('enable_purchases') ?? false);
    }

    public static function budgetConversionTarget(?int $tenantId = null): float
    {
        $query = static::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $configured = $query->value('budget_conversion_target');

        if ($configured !== null) {
            return (float) $configured;
        }

        return 60.0;
    }

    public static function customerFeedbackRequestDelayDays(?int $tenantId = null): int
    {
        $query = static::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $configured = $query->value('customer_feedback_request_delay_days');

        if ($configured !== null) {
            return max(1, (int) $configured);
        }

        return 7;
    }

    public static function paymentRecoveryTarget(?int $tenantId = null): float
    {
        $query = static::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $configured = $query->value('payment_recovery_target');

        if ($configured !== null) {
            return (float) $configured;
        }

        return 70.0;
    }
}
