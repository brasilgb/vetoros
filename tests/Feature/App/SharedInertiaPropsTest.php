<?php

use App\Models\App\Customer;
use App\Models\App\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function partialReloadHeaders(string $component, string $version, string $only): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $only,
    ];
}

test('full visit no longer shares the whole customer base', function () {
    $user = User::factory()->create();
    Customer::factory()->forTenant($user->tenant_id)->count(3)->create();

    $this->actingAs($user)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('customers')
            ->has('auth.user')
            ->has('othersetting')
            ->has('cashier')
            ->has('fiscalSetting')
            ->has('subscription')
            ->has('company')
            ->has('equipments')
            ->has('technicals')
            ->has('orderStatus')
            ->has('performanceAlert')
            ->has('customerFeedbackAlert')
            ->has('taskIndicator')
            ->where('notifications', 0)
        );
});

test('notifications polling only resolves the notifications prop', function () {
    $user = User::factory()->create();
    $sender = User::factory()->create(['tenant_id' => $user->tenant_id]);
    Customer::factory()->forTenant($user->tenant_id)->count(3)->create();
    Message::factory()->forTenant($user->tenant_id, $sender->id, $user->id)->count(2)->create(['status' => 0]);
    Message::factory()->forTenant($user->tenant_id, $sender->id, $user->id)->create(['status' => 1]);

    // O reload parcial também executa o controller da página; /app/messages tem um controller leve.
    $page = $this->actingAs($user)->get('/app/messages')->viewData('page');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->actingAs($user)
        ->get('/app/messages', partialReloadHeaders($page['component'], (string) $page['version'], 'notifications'))
        ->assertOk()
        ->assertHeader('X-Inertia', 'true');

    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect(array_keys($response->json('props')))->toEqualCanonicalizing(['notifications', 'errors'])
        ->and($response->json('props.notifications'))->toBe(2)
        ->and($queries)->toContain('messages')
        ->and($queries)->not->toContain('customers')
        ->and($queries)->not->toContain('order_logs')
        ->and($queries)->not->toContain('equipment')
        ->and($queries)->not->toContain('plans')
        ->and($queries)->not->toContain('insert into')
        ->and($queries)->not->toContain('fiscal_settings')
        ->and($queries)->not->toContain('cash_sessions')
        ->and($queries)->not->toContain('tenant_feedback');
});
