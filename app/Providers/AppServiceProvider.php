<?php

namespace App\Providers;

use App\Listeners\SetTenantIdInSession;
use App\Models\App\AccountPayable;
use App\Models\App\CashSession;
use App\Models\App\Expense;
use App\Models\App\Message;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\App\PurchaseOrder;
use App\Models\App\Sale;
use App\Models\App\Schedule;
use App\Models\User;
use App\Policies\AccountPayablePolicy;
use App\Policies\CashSessionPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\MessagePolicy;
use App\Policies\OrderPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\SalePolicy;
use App\Policies\SchedulePolicy;
use App\Policies\UserPolicy;
use App\Support\TenantMailConfig;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::unguard();

        // Com APP_URL em HTTPS, toda URL gerada (inclusive em filas, agendador e e-mails,
        // fora de uma requisição) sai em HTTPS, sem depender de APP_ENV nem de proxy.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
        Gate::policy(Schedule::class, SchedulePolicy::class);
        Gate::policy(Sale::class, SalePolicy::class);
        Gate::policy(CashSession::class, CashSessionPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(AccountPayable::class, AccountPayablePolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::define('suppliers.access', fn ($user) => $user->hasPermission('suppliers') && Other::purchasesEnabled($user->tenant_id));
        Gate::define('quality.view', fn ($user) => $user->hasPermission('reports'));
        Gate::define('quality.manage', fn ($user) => $user->hasPermission('reports'));
        Gate::define('reports.view', fn ($user) => $user->hasPermission('reports'));
        Gate::define('parts.access', fn ($user) => $user->hasPermission('parts'));
        Gate::define('company.access', fn ($user) => $user->hasPermission('company'));
        Gate::define('other-settings.access', fn ($user) => $user->hasPermission('other_settings'));
        Gate::define('auxiliary-apps.access', fn ($user) => $user->hasPermission('settings'));
        Gate::define('receipts.access', fn ($user) => $user->hasPermission('receipts'));
        Gate::define('fiscal-documents.access', fn ($user) => $user->hasPermission('fiscal_documents'));
        Gate::define('whatsapp-messages.access', fn ($user) => $user->hasPermission('whatsapp_messages'));
        Gate::define('customers.access', fn ($user) => $user->hasPermission('customers'));
        Gate::define('customer-equipments.access', fn ($user) => $user->hasPermission('customers'));
        Gate::define('equipments.access', fn ($user) => $user->hasPermission('register_equipments'));
        Gate::define('checklists.access', fn ($user) => $user->hasPermission('register_checklists'));
        Gate::define('services.access', fn ($user) => $user->hasPermission('settings'));
        Gate::define('label-printing.access', fn ($user) => $user->hasPermission('label_printing'));

        Event::listen(
            Login::class,
            SetTenantIdInSession::class
        );
        $tenantId = resolveCurrentTenantId();
        TenantMailConfig::applyForTenantId($tenantId ? (int) $tenantId : null);
    }
}
