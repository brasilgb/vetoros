<?php

namespace Tests\Feature\App;

use App\Models\App\Part;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->user);
    }

    public function test_it_records_stock_in_movement_when_part_is_created(): void
    {
        $response = $this->post(route('app.parts.store'), [
            'reference_number' => 'REF-TEST-001',
            'type' => 'part',
            'is_sellable' => true,
            'category' => 'Conectores',
            'name' => 'Conector USB-C',
            'description' => 'Conector para placa',
            'manufacturer' => 'VetorOS',
            'model_compatibility' => 'Notebook',
            'cost_price' => 25,
            'sale_price' => 80,
            'quantity' => 4,
            'minimum_stock_level' => 1,
            'location' => 'A1',
            'status' => true,
        ]);

        $response->assertRedirect(route('app.parts.index'));

        $part = Part::query()->firstOrFail();

        $this->assertDatabaseHas('part_movements', [
            'tenant_id' => $this->tenant->id,
            'part_id' => $part->id,
            'user_id' => $this->user->id,
            'movement_type' => 'entrada',
            'quantity' => 4,
            'reason' => 'Cadastro inicial',
        ]);
    }

    public function test_it_defaults_part_sellable_flag_when_missing(): void
    {
        $response = $this->post(route('app.parts.store'), [
            'reference_number' => 'REF-TEST-002',
            'type' => 'part',
            'category' => 'Conectores',
            'name' => 'Conector HDMI',
            'description' => 'Conector HDMI para placa',
            'manufacturer' => 'VetorOS',
            'model_compatibility' => 'Notebook',
            'cost_price' => 20,
            'sale_price' => 70,
            'quantity' => 2,
            'minimum_stock_level' => 1,
        ]);

        $response->assertRedirect(route('app.parts.index'));

        $this->assertDatabaseHas('parts', [
            'tenant_id' => $this->tenant->id,
            'reference_number' => 'REF-TEST-002',
            'is_sellable' => false,
            'status' => true,
        ]);
    }

    public function test_it_allows_reusing_category_and_manufacturer_for_multiple_parts(): void
    {
        Part::factory()->forTenant($this->tenant->id)->create([
            'category' => 'Placa 1-3',
            'manufacturer' => 'Fabricante 3',
        ]);

        $response = $this->post(route('app.parts.store'), [
            'reference_number' => 'REF-TEST-003',
            'type' => 'part',
            'is_sellable' => false,
            'category' => 'Placa 1-3',
            'name' => 'Memoria',
            'description' => 'Memoria ddr5 128gb',
            'manufacturer' => 'Fabricante 3',
            'cost_price' => 456.98,
            'sale_price' => 1025.63,
            'quantity' => 0,
            'minimum_stock_level' => 2,
            'status' => true,
        ]);

        $response->assertRedirect(route('app.parts.index'));

        $this->assertDatabaseCount('parts', 2);
        $this->assertSame(2, Part::where('category', 'Placa 1-3')->count());
    }

    public function test_it_filters_parts_by_barcode_digits_from_reference_number(): void
    {
        $matched = Part::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Tela Notebook',
            'reference_number' => 'REF-987654321',
        ]);

        Part::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Bateria Notebook',
            'reference_number' => 'REF-111222333',
        ]);

        $response = $this->get(route('app.parts.index', [
            'search' => '987654321',
        ]));

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('app/parts/index')
                ->where('parts.data.0.id', $matched->id)
                ->where('parts.data.0.reference_number', 'REF-987654321')
                ->has('parts.data', 1)
            );
    }

    public function test_it_filters_parts_by_part_number(): void
    {
        $matched = Part::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Cabo USB-C',
            'part_number' => 'PN-445566',
        ]);

        Part::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Cabo HDMI',
            'part_number' => 'PN-778899',
        ]);

        $response = $this->get(route('app.parts.index', [
            'search' => '445566',
        ]));

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('app/parts/index')
                ->where('parts.data.0.id', $matched->id)
                ->where('parts.data.0.part_number', 'PN-445566')
                ->has('parts.data', 1)
            );
    }

    public function test_it_records_adjustment_movement_when_part_quantity_changes(): void
    {
        $part = Part::factory()->forTenant($this->tenant->id)->create([
            'quantity' => 4,
        ]);

        $response = $this->patch(route('app.parts.update', $part), [
            'reference_number' => $part->reference_number,
            'type' => $part->type,
            'is_sellable' => $part->is_sellable,
            'category' => $part->category,
            'name' => $part->name,
            'description' => $part->description,
            'manufacturer' => $part->manufacturer,
            'model_compatibility' => $part->model_compatibility,
            'cost_price' => $part->cost_price,
            'sale_price' => $part->sale_price,
            'quantity' => 7,
            'minimum_stock_level' => $part->minimum_stock_level,
            'location' => $part->location,
            'status' => $part->status,
        ]);

        $response->assertRedirect(route('app.parts.show', ['part' => $part->id]));

        $this->assertDatabaseHas('part_movements', [
            'tenant_id' => $this->tenant->id,
            'part_id' => $part->id,
            'user_id' => $this->user->id,
            'movement_type' => 'ajuste',
            'quantity' => 3,
            'reason' => 'Ajuste de estoque',
        ]);
    }
}
