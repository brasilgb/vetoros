<?php

namespace Tests\Feature\Site;

use App\Models\Admin\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * VETOROS-COMERCIAL-01: preços não aparecem no site público; o visitante
 * solicita orçamento pelo WhatsApp. Assinantes continuam vendo seus valores.
 */
class PublicPricingTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE_PATTERNS = [
        '/R\$/u',
        '/(?:^|[\s>(])\d{1,4},\d{2}(?![\d,])/mu',
        '/\/m[eê]s(?![a-z])/u',
        '/por m[eê]s/u',
        '/priceCurrency/u',
        '/"price"/u',
        '/formatCurrency|MONTHLY_BASE_PRICE/u',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_public_pages_render_without_prices_in_html_metadata_or_inertia_props(): void
    {
        foreach (['/', '/planos'] as $url) {
            $response = $this->get($url)->assertOk();
            $html = $response->getContent();

            foreach (self::PRICE_PATTERNS as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $html, "Preço exposto em {$url}: {$pattern}");
            }

            $this->assertStringContainsString('application/ld+json', $html);
            $this->assertStringNotContainsString('"offers"', $html);
        }

        $this->get('/planos')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('site/plans/index')
            // Prop compartilhado: só traz planos para usuário autenticado com tenant.
            ->where('plans', []));
    }

    public function test_public_site_sources_contain_no_prices_and_quote_by_whatsapp(): void
    {
        $files = collect(File::allFiles(resource_path('js/pages/site')))
            ->filter(fn ($file) => in_array($file->getExtension(), ['ts', 'tsx'], true));

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = $file->getContents();

            foreach (self::PRICE_PATTERNS as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $source, "Preço no código público: {$file->getRelativePathname()} ({$pattern})");
            }

            $this->assertStringNotContainsString('A partir de', $source, $file->getRelativePathname());
        }

        $hero = File::get(resource_path('js/pages/site/components/hero.tsx'));
        $this->assertStringContainsString('Solicite um orçamento', $hero);
    }

    public function test_monthly_semiannual_and_annual_plans_have_contextual_whatsapp_quote(): void
    {
        $data = File::get(resource_path('js/pages/site/components/pricing-data.ts'));
        $pricing = File::get(resource_path('js/pages/site/components/pricing.tsx'));
        $contact = File::get(resource_path('js/pages/site/components/site-contact.ts'));

        $this->get('/planos')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('publicPlans', 3)
            ->where('publicPlans.0.name', 'Mensal')
            ->where('publicPlans.0.periodicity', 'mensal')
            ->where('publicPlans.1.name', 'Semestral')
            ->where('publicPlans.1.periodicity', 'semestral')
            ->where('publicPlans.2.name', 'Anual')
            ->where('publicPlans.2.periodicity', 'anual')
            ->where('publicPlans.2.popular', true));

        $this->assertStringContainsString('solicitar um orçamento do plano ${planName} (periodicidade ${periodicity})', $data);
        $this->assertStringContainsString('Consultar pelo WhatsApp', $pricing);
        $this->assertStringContainsString('whatsappLink(quoteMessage(plan.name, plan.periodicity))', $pricing);

        // Mesmo número comercial que o site já usava; nenhum número novo.
        $this->assertStringContainsString("COMMERCIAL_WHATSAPP = '5551998931325'", $contact);
        foreach (File::allFiles(resource_path('js/pages/site')) as $file) {
            if ($file->getFilename() !== 'site-contact.ts') {
                $this->assertStringNotContainsString('wa.me/', $file->getContents(), $file->getRelativePathname());
            }
        }
    }

    public function test_database_prices_stay_private_until_public_prices_are_enabled(): void
    {
        // Valores definidos pelo RootAdmin nos planos já existentes.
        Plan::query()->where('billing_months', 1)->update(['value' => 123.45]);
        Plan::query()->where('billing_months', 6)->update(['value' => 0]);
        Plan::query()->where('billing_months', 12)->update(['value' => 999.99]);

        $html = $this->get('/planos')->assertOk()->getContent();
        $this->assertStringNotContainsString('123,45', $html);
        $this->assertStringNotContainsString('123.45', $html);
        $this->assertStringNotContainsString('price_label', $html);

        // Arquitetura pronta: ao habilitar, o valor sai do banco, sem número no frontend.
        config(['commercial.public_prices_enabled' => true]);
        $this->get('/planos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('publicPlans.0.price_label', 'R$ 123,45')
            ->missing('publicPlans.1.price_label')
            ->where('publicPlans.2.price_label', 'R$ 999,99'));
    }

    public function test_only_root_admin_manages_plan_prices(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Mensal',
            'slug' => 'mensal-teste',
            'value' => 89.90,
            'billing_months' => 1,
            'description' => 'Plano mensal',
        ]);
        // Criado antes de autenticar: User herda o tenant do usuário logado ao ser criado.
        $root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_APP]);
        $payload = ['name' => 'Mensal', 'slug' => 'mensal-teste', 'description' => 'Plano mensal', 'value' => 1, 'billing_months' => 1];

        $this->put(route('admin.plans.update', $plan), $payload)->assertRedirect(route('login'));

        $tenantAdmin = User::factory()->forTenant(Tenant::factory()->create()->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->actingAs($tenantAdmin)->put(route('admin.plans.update', $plan), $payload)->assertRedirect();
        $this->assertSame('89.90', number_format((float) $plan->refresh()->value, 2, '.', ''));

        $this->actingAs($root)->put(route('admin.plans.update', $plan), [...$payload, 'value' => 99.90])
            ->assertRedirect(route('admin.plans.index'));
        $this->assertSame('99.90', number_format((float) $plan->refresh()->value, 2, '.', ''));
    }

    public function test_existing_subscribers_still_see_plan_values_on_renewal_flow(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Anual',
            'slug' => 'anual-teste',
            'value' => 838.80,
            'billing_months' => 12,
            'description' => 'Plano anual',
        ]);
        $tenant = Tenant::factory()->create(['expires_at' => now()->subMonth(), 'subscription_status' => 'blocked']);
        $user = User::factory()->forTenant($tenant->id)->create();

        $this->actingAs($user)
            ->get(route('subscription.blocked'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Subscription/Blocked')
                ->where('plans', fn ($plans) => collect($plans)->contains(fn ($item) => $item['id'] === $plan->id && (float) $item['value'] === 838.80)));

        $this->assertSame('838.80', number_format((float) $plan->refresh()->value, 2, '.', ''));
    }
}
