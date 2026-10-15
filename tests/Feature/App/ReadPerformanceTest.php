<?php

use App\Models\Admin\SpedyPlatformSetting;
use App\Models\App\Customer;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\User;
use App\Services\Fiscal\Spedy\SpedyPlatformConfig;
use App\Support\OrderStatus;
use App\Support\TenantMailConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** Leituras repetidas e props pesadas em toda navegação (análise de lentidão, 2026-10-15). */
function queriesDuring(Closure $callback): Collection
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    return $queries;
}

test('orders list reads the tenant mail settings once, not once per row', function () {
    $user = User::factory()->create();
    Other::factory()->forTenant($user->tenant_id)->create([
        'mail_host' => 'smtp.example.test', 'mail_port' => 587, 'mail_username' => 'os@example.test',
        'mail_password' => Crypt::encryptString('secret'),
    ]);
    $customer = Customer::factory()->forTenant($user->tenant_id)->create(['email' => 'cliente@example.test']);
    Order::factory()->forTenant($user->tenant_id)->count(8)->create(['customer_id' => $customer->id]);

    $queries = queriesDuring(fn () => $this->actingAs($user)->get('/app/orders')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 8)->where('orders.data.0.can_send_payment_reminder', true)));

    expect($queries->filter(fn ($sql) => str_contains($sql, 'mail_host'))->count())->toBeLessThanOrEqual(1)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'information_schema'))->count())->toBeLessThanOrEqual(1);
});

test('mail settings saved during the request are not read stale', function () {
    $user = User::factory()->create();
    $other = Other::factory()->forTenant($user->tenant_id)->create(['mail_host' => null]);

    expect(TenantMailConfig::hasConfiguredForTenantId($user->tenant_id))->toBeFalse();

    $other->update(['mail_host' => 'smtp.example.test', 'mail_port' => 587, 'mail_username' => 'os@example.test', 'mail_password' => Crypt::encryptString('x')]);

    expect(TenantMailConfig::hasConfiguredForTenantId($user->tenant_id))->toBeTrue();
});

test('spedy platform row is read once per request and refreshed after saving', function () {
    config(['services.spedy.owner_api_key' => null]);

    $queries = queriesDuring(function () {
        SpedyPlatformConfig::ownerApiKey();
        SpedyPlatformConfig::environment();
        SpedyPlatformConfig::webhookSecret();
    });
    expect($queries->filter(fn ($sql) => str_contains($sql, 'spedy_platform_settings'))->count())->toBe(1)
        ->and(SpedyPlatformConfig::ownerApiKey())->toBeNull();

    SpedyPlatformSetting::current()->forceFill(['owner_api_key' => 'saved-owner-key', 'environment' => 'production'])->save();

    expect(SpedyPlatformConfig::ownerApiKey())->toBe('saved-owner-key')
        ->and(SpedyPlatformConfig::environment())->toBe('production');
});

test('every navigation carries only the route query, not the whole route list', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/app/company?search=abc')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ziggy.query.search', 'abc')
            ->has('ziggy.location')
            ->missing('ziggy.routes'));
});

test('dashboard indicators are not computed on other pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/app/company')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('customerFeedbackAlert')
            ->missing('taskIndicator')
            ->has('performanceAlert'));
});

test('menu performance alert is cached instead of rescanning logs on every page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/app/company')->assertOk();
    $queries = queriesDuring(fn () => $this->actingAs($user)->get('/app/company')->assertOk());

    expect($queries->filter(fn ($sql) => str_contains($sql, 'order_logs'))->count())->toBe(0);
});

test('approved budgets badge receives only ids', function () {
    $user = User::factory()->create();
    Order::factory()->forTenant($user->tenant_id)->create(['service_status' => OrderStatus::BUDGET_APPROVED]);

    $this->actingAs($user)->get('/app/company')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('orderStatus', 1)
            ->has('orderStatus.0', fn (Assert $order) => $order->has('id')));
});
