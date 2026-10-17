<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Setting;
use App\Models\User;
use App\Support\CommercialContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialWhatsappSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::factory()->create([
            'tenant_id' => null,
            'user_number' => null,
            'roles' => User::ROLE_ROOT_SYSTEM,
        ]));
    }

    public function test_root_can_save_a_valid_brazilian_phone_normalized_for_wa_me(): void
    {
        $setting = Setting::create(['name' => 'VetorOS']);

        $this->put(route('admin.settings.update', $setting), [
            'name' => 'VetorOS',
            'commercial_whatsapp' => '+55 (51) 99893-1325',
            'commercial_whatsapp_name' => 'Equipe comercial',
            'commercial_whatsapp_message' => 'Quero conhecer',
            'commercial_whatsapp_public' => true,
            'commercial_whatsapp_internal' => true,
        ])->assertRedirect(route('admin.settings.index'));

        $this->assertDatabaseHas('settings', [
            'id' => $setting->id,
            'commercial_whatsapp' => '5551998931325',
            'commercial_whatsapp_name' => 'Equipe comercial',
            'commercial_whatsapp_message' => 'Quero conhecer',
        ]);
    }

    public function test_invalid_phone_is_rejected_and_blank_phone_keeps_compatibility_fallback(): void
    {
        $setting = Setting::create(['name' => 'VetorOS']);
        $payload = [
            'name' => 'VetorOS',
            'commercial_whatsapp_public' => true,
            'commercial_whatsapp_internal' => true,
        ];

        $this->from(route('admin.settings.index'))
            ->put(route('admin.settings.update', $setting), [...$payload, 'commercial_whatsapp' => '12345'])
            ->assertSessionHasErrors('commercial_whatsapp');
        $this->assertDatabaseHas('settings', ['id' => $setting->id, 'commercial_whatsapp' => null]);

        $this->put(route('admin.settings.update', $setting), [...$payload, 'commercial_whatsapp' => ''])
            ->assertRedirect(route('admin.settings.index'));
        $this->assertSame('5551998931325', CommercialContact::current()['number']);
    }

    public function test_public_landing_and_pricing_receive_the_same_configured_contact_channel(): void
    {
        Setting::create([
            'name' => 'VetorOS',
            'commercial_whatsapp' => '5551998931325',
            'commercial_whatsapp_public' => true,
            'commercial_whatsapp_internal' => true,
        ]);

        $this->get(route('home'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('site/home/index')
            ->where('contactChannel.number', '5551998931325')
            ->where('contactChannel.publicEnabled', true));
    }
}
