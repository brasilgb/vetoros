<?php

namespace App\Models;

use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\Branch;
use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use App\Models\App\Customer;
use App\Models\App\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    public const SUBSCRIPTION_ACTIVE = 'active';

    public const SUBSCRIPTION_GRACE = 'grace';

    public const SUBSCRIPTION_BLOCKED = 'blocked';

    protected $guarded = ['_method'];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_subscription_notice_sent_at' => 'datetime',
        'automatic_fiscal_emission_enabled' => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(TenantFeedback::class);
    }

    public function improvementRequests(): HasMany
    {
        return $this->hasMany(TenantImprovementRequest::class);
    }

    public function adminFiscalDocuments(): HasMany
    {
        return $this->hasMany(AdminFiscalDocument::class);
    }

    public function latestAdminFiscalDocument(): HasOne
    {
        return $this->hasOne(AdminFiscalDocument::class)->latestOfMany();
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class)->whereNull('roles');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function getGraceDaysRemainingAttribute()
    {
        if (! $this->expires_at) {
            return 0;
        }

        $limitDate = $this->subscriptionGraceLimit();
        $now = now();

        // Se já passou até da carência, retorna 0
        if ($now->greaterThan($limitDate)) {
            return 0;
        }

        // Retorna a diferença em dias
        return (int) $now->diffInDays($limitDate, false);
    }

    public function subscriptionGraceLimit(): ?Carbon
    {
        if (! $this->expires_at) {
            return null;
        }

        return Carbon::parse($this->expires_at)->copy()->addDays(3)->startOfDay();
    }

    public function subscriptionBucket(?Carbon $today = null): string
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        if (! $this->expires_at) {
            return self::SUBSCRIPTION_ACTIVE;
        }

        $expiresAt = $this->expires_at->copy()->startOfDay();
        if ($expiresAt->gte($today)) {
            return self::SUBSCRIPTION_ACTIVE;
        }

        $graceLimit = $this->subscriptionGraceLimit();
        if ($graceLimit && $expiresAt->gte($today->copy()->subDays(3)->startOfDay())) {
            return self::SUBSCRIPTION_GRACE;
        }

        return self::SUBSCRIPTION_BLOCKED;
    }

    public function persistedSubscriptionStatus(): string
    {
        return match ($this->subscriptionBucket()) {
            self::SUBSCRIPTION_ACTIVE => 'active',
            self::SUBSCRIPTION_GRACE => 'expired',
            self::SUBSCRIPTION_BLOCKED => 'blocked',
            default => 'active',
        };
    }

    public function subscriptionLabel(): string
    {
        return match ($this->subscriptionBucket()) {
            self::SUBSCRIPTION_ACTIVE => 'Ativa',
            self::SUBSCRIPTION_GRACE => 'Em carencia',
            self::SUBSCRIPTION_BLOCKED => 'Bloqueada',
            default => 'Pendente',
        };
    }

    public function attentionLabel(?Carbon $today = null): string
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $bucket = $this->subscriptionBucket($today);

        if (! $this->plan_id) {
            return 'Sem plano';
        }

        if (! $this->expires_at) {
            return 'Sem vencimento';
        }

        if ($bucket === self::SUBSCRIPTION_BLOCKED) {
            return 'Bloqueada';
        }

        if ($bucket === self::SUBSCRIPTION_GRACE) {
            return 'Em carencia';
        }

        if ($this->expires_at->isSameDay($today)) {
            return 'Vence hoje';
        }

        return 'Vence em breve';
    }

    public function attentionPriority(?Carbon $today = null): int
    {
        return match ($this->attentionLabel($today)) {
            'Bloqueada' => 1,
            'Em carencia' => 2,
            'Vence hoje' => 3,
            'Sem plano' => 4,
            'Sem vencimento' => 5,
            'Vence em breve' => 6,
            default => 99,
        };
    }

    public function daysUntilExpiration(?Carbon $today = null): ?int
    {
        if (! $this->expires_at) {
            return null;
        }

        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return (int) $today->diffInDays($this->expires_at->copy()->startOfDay(), false);
    }

    public function subscriptionNoticeData(?Carbon $today = null): ?array
    {
        if (! $this->plan_id || ! $this->expires_at) {
            return null;
        }

        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $daysUntilExpiration = $this->daysUntilExpiration($today);
        $scenario = match ($daysUntilExpiration) {
            3 => 'expires_in_3_days',
            1 => 'expires_tomorrow',
            0 => 'expires_today',
            -2 => 'grace',
            -4 => 'blocked',
            default => null,
        };

        if (! $scenario) {
            return null;
        }

        return $this->buildSubscriptionNotice($scenario, $today);
    }

    public function buildSubscriptionNotice(string $scenario, ?Carbon $today = null): ?array
    {
        if (! $this->plan_id || ! $this->expires_at) {
            return null;
        }

        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $paymentUrl = rtrim((string) config('app.url'), '/').'/subscription/blocked';

        return match ($scenario) {
            'expires_in_3_days' => [
                'key' => 'expires_in_3_days_'.$today->format('Ymd'),
                'subject' => 'Sua assinatura vence em 3 dias',
                'preview' => 'Sua assinatura vence em 3 dias. Planeje a renovação com tranquilidade.',
                'headline' => 'Sua assinatura vence em 3 dias',
                'message' => 'Estamos passando para lembrar que a assinatura da sua empresa vence em 3 dias. Assim voce consegue se organizar com antecedencia e evita correria no ultimo momento.',
                'cta_url' => $paymentUrl,
                'cta_label' => 'Ver renovacao',
                'footer' => 'Esse lembrete antecipado ajuda sua equipe a manter o acesso continuo sem surpresas.',
            ],
            'expires_tomorrow' => [
                'key' => 'expires_tomorrow_'.$today->format('Ymd'),
                'subject' => 'Sua assinatura vence amanha',
                'preview' => 'Sua assinatura vence amanha. Evite interrupcoes no acesso.',
                'headline' => 'Sua assinatura vence amanha',
                'message' => 'Percebemos que a assinatura da sua empresa vence amanha. Para evitar qualquer interrupcao no acesso, recomendamos regularizar com antecedencia.',
                'cta_url' => $paymentUrl,
                'cta_label' => 'Regularizar agora',
                'footer' => 'Se precisar de ajuda com renovacao, nosso time pode apoiar voce rapidamente.',
            ],
            'expires_today' => [
                'key' => 'expires_today_'.$today->format('Ymd'),
                'subject' => 'Sua assinatura vence hoje',
                'preview' => 'Sua assinatura vence hoje. Regularize para manter o acesso.',
                'headline' => 'Sua assinatura vence hoje',
                'message' => 'Hoje e o ultimo dia de vigencia da sua assinatura. Regularize agora para manter o acesso continuo da sua equipe.',
                'cta_url' => $paymentUrl,
                'cta_label' => 'Renovar assinatura',
                'footer' => 'Quanto antes a regularizacao for feita, mais suave fica a continuidade do uso.',
            ],
            'grace' => [
                'key' => 'grace_'.$today->format('Ymd'),
                'subject' => 'Sua assinatura esta em carencia',
                'preview' => 'Sua assinatura venceu e esta em periodo de carencia.',
                'headline' => 'Sua assinatura esta em periodo de carencia',
                'message' => 'Sua assinatura venceu recentemente e sua empresa esta em periodo de carencia. Este e um lembrete importante para regularizar antes do bloqueio do acesso.',
                'cta_url' => $paymentUrl,
                'cta_label' => 'Regularizar assinatura',
                'footer' => 'Durante a carencia, o ideal e nao deixar para o ultimo momento.',
            ],
            'blocked' => [
                'key' => 'blocked_'.$today->format('Ymd'),
                'subject' => 'Sua assinatura foi bloqueada',
                'preview' => 'O acesso foi bloqueado por falta de renovacao da assinatura.',
                'headline' => 'Sua assinatura foi bloqueada',
                'message' => 'Como a assinatura nao foi regularizada dentro do periodo de carencia, o acesso da empresa foi bloqueado. Assim que a renovacao for concluida, o uso pode ser retomado normalmente.',
                'cta_url' => $paymentUrl,
                'cta_label' => 'Reativar acesso',
                'footer' => 'Se precisar de suporte para reativacao, estamos prontos para ajudar.',
            ],
            default => null,
        };
    }
}
