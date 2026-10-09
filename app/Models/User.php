<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\App\Expense;
use App\Models\App\Message;
use App\Models\App\Order;
use App\Models\App\OrderCommission;
use App\Models\App\Schedule;
use App\Tenantable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, Tenantable;

    public const ROLE_ROOT_SYSTEM = 99;

    public const ROLE_ROOT_APP = 9;

    public const ROLE_ADMIN = 1;

    public const ROLE_OPERATOR = 2;

    public const ROLE_TECHNICIAN = 3;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_number',
        'name',
        'email',
        'telephone',
        'whatsapp',
        'avatar',
        'password',
        'roles',
        'can_view_all_orders',
        'commission_percentage',
        'status',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'can_view_all_orders' => 'boolean',
            'commission_percentage' => 'decimal:2',
            'last_login_at' => 'datetime',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by');
    }

    public function orderCommissions(): HasMany
    {
        return $this->hasMany(OrderCommission::class, 'user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function improvementRequests(): HasMany
    {
        return $this->hasMany(TenantImprovementRequest::class);
    }

    public function isRoot(): bool
    {
        return in_array($this->roles, [self::ROLE_ROOT_SYSTEM, self::ROLE_ROOT_APP], true);
    }

    /**
     * RootAdmin da plataforma: usuário sem tenant e com papel root. É a única política
     * de acesso a /admin e /admin/fiscal (AdminAccessMiddleware e RootAdminOnly).
     */
    public function isRootAdmin(): bool
    {
        return $this->tenant_id === null && $this->isRoot();
    }

    public function isAdministrator(): bool
    {
        return $this->roles === self::ROLE_ADMIN;
    }

    public function isOperator(): bool
    {
        return $this->roles === self::ROLE_OPERATOR;
    }

    public function isTechnician(): bool
    {
        return $this->roles === self::ROLE_TECHNICIAN;
    }

    public function canUseTechnicianApp(): bool
    {
        return $this->isTechnician() || $this->isAdministrator();
    }

    public function canViewAllOrders(): bool
    {
        return ! $this->isTechnician() || (bool) $this->can_view_all_orders;
    }

    public function roleKey(): string
    {
        return match ($this->roles) {
            self::ROLE_ROOT_SYSTEM => 'root_system',
            self::ROLE_ROOT_APP => 'root_app',
            self::ROLE_ADMIN => 'administrator',
            self::ROLE_OPERATOR => 'operator',
            self::ROLE_TECHNICIAN => 'technician',
            default => 'unknown',
        };
    }

    public function permissions(): array
    {
        if ($this->isRoot() || $this->isAdministrator()) {
            return [
                'dashboard',
                'customers',
                'orders',
                'budgets',
                'schedules',
                'messages',
                'parts',
                'purchase_orders',
                'suppliers',
                'finance',
                'sales',
                'reports',
                'users',
                'users.view',
                'users.create',
                'users.update',
                'users.delete',
                'settings',
                'company',
                'whatsapp_messages',
                'receipts',
                'fiscal_documents',
                'label_printing',
                'register_equipments',
                'register_checklists',
                'other_settings',
            ];
        }

        if ($this->isOperator()) {
            return [
                'dashboard',
                'customers',
                'orders',
                'budgets',
                'schedules',
                'messages',
                'parts',
                'purchase_orders',
                'suppliers',
                'finance',
                'sales',
                'reports',
                'users',
                'users.view',
                'users.create',
                'users.update',
                'users.delete',
                'settings',
                'company',
                'whatsapp_messages',
                'receipts',
                'fiscal_documents',
                'label_printing',
                'register_equipments',
                'register_checklists',
                'other_settings',
            ];
        }

        if ($this->isTechnician()) {
            return [
                'dashboard',
                'orders',
                'schedules',
                'messages',
            ];
        }

        return [];
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
