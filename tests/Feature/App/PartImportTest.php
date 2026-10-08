<?php

namespace Tests\Feature\App;

use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PartImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PartImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private const HEADER = 'codigo;nome;descricao;tipo;categoria;fabricante;compatibilidade;vendavel;preco_custo;preco_venda;estoque_inicial;estoque_minimo;localizacao;ncm;cfop;ativo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    public function test_template_has_bom_semicolon_header_and_ignored_example(): void
    {
        $response = $this->get(route('app.parts.import.template'))->assertOk();

        $content = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF".self::HEADER."\r\n", $content);
        $this->assertStringContainsString('#EXEMPLO', $content);
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        // O próprio modelo, importado sem alterações, não cria produto (linha de exemplo ignorada).
        $this->expectArquivoError($this->upload('preview', substr($content, 3)), 'linha de exemplo');
    }

    public function test_valid_import_creates_parts_with_stock_movement_and_cost(): void
    {
        $csv = $this->csv([
            'TL-001;Tela LCD A10;Tela com touch;peca;Telas;Samsung;Galaxy A10;sim;1.234,56;2.499,90;5;2;Prat. B3;85177099;5102;sim',
            '7891234567895;Película 3D;Película de vidro;produto;Acessórios;Genérica;;nao;3,50;19,90;0;10;;;;nao',
        ]);

        $preview = $this->upload('preview', $csv)->assertOk()->json();
        $this->assertSame(['found' => 2, 'new' => 2, 'duplicates' => 0, 'errors' => 0, 'ignored' => 0], $preview['summary']);
        $this->assertSame(0, Part::query()->count(), 'a prévia não grava nada');

        $result = $this->upload('store', $csv)->assertOk()->json();
        $this->assertSame(2, $result['summary']['imported']);

        $screen = Part::query()->where('reference_number', 'TL-001')->sole();
        $this->assertSame('part', $screen->type);
        $this->assertSame(1234.56, (float) $screen->cost_price);
        $this->assertSame(2499.90, (float) $screen->sale_price);
        $this->assertSame(5, (int) $screen->quantity);
        $this->assertSame(2, (int) $screen->minimum_stock_level);
        $this->assertSame('85177099', $screen->ncm);
        $this->assertSame($this->tenant->id, (int) $screen->tenant_id);
        $this->assertNotNull($screen->part_number);

        $movement = PartMovement::query()->where('part_id', $screen->id)->sole();
        $this->assertSame(PartMovement::TYPE_STOCK_IN, $movement->movement_type);
        $this->assertSame(5, (int) $movement->quantity);
        $this->assertSame(1234.56, (float) $movement->unit_cost);
        $this->assertSame(6172.80, (float) $movement->total_cost);
        $this->assertSame($this->user->id, (int) $movement->user_id);

        $film = Part::query()->where('reference_number', '7891234567895')->sole();
        $this->assertSame('product', $film->type);
        $this->assertFalse((bool) $film->is_sellable);
        $this->assertFalse((bool) $film->status);
        $this->assertSame(0, PartMovement::query()->where('part_id', $film->id)->count(), 'estoque zero não gera movimento');
    }

    public function test_empty_file_and_wrong_headers_are_rejected(): void
    {
        $this->expectArquivoError($this->upload('preview', ''), 'vazio');
        $this->expectArquivoError($this->upload('preview', "\xEF\xBB\xBF"), 'vazio');
        $this->expectArquivoError($this->upload('preview', "codigo,nome,descricao\nA,B,C"), 'Cabeçalho inválido');
        $this->expectArquivoError($this->upload('preview', "codigo;nome;preco\nA;B;1"), 'Faltando');
        $this->expectArquivoError($this->upload('preview', self::HEADER."\n"), 'só o cabeçalho');
        $this->assertSame(0, Part::query()->count());
    }

    public function test_money_and_quantity_formats(): void
    {
        $this->assertSame(1234.56, PartImportService::money('1.234,56'));
        $this->assertSame(1234.56, PartImportService::money('1234,56'));
        $this->assertSame(1234.56, PartImportService::money('1234.56'));
        $this->assertSame(10.0, PartImportService::money('R$ 10,00'));
        $this->assertNull(PartImportService::money('-5,00'));
        $this->assertNull(PartImportService::money('1,234'));
        $this->assertNull(PartImportService::money('abc'));
        $this->assertSame(1000, PartImportService::quantity('1.000'));
        $this->assertNull(PartImportService::quantity('2,5'));
        $this->assertNull(PartImportService::quantity('-1'));
    }

    public function test_duplicates_are_ignored_without_touching_existing_part(): void
    {
        $existing = Part::factory()->forTenant($this->tenant->id)->create([
            'reference_number' => 'TL-001', 'cost_price' => 50, 'sale_price' => 90, 'quantity' => 7,
        ]);
        $csv = $this->csv([
            'tl-001;Outra tela;Desc;peca;Telas;Samsung;;sim;999,00;999,00;100;1;;;;sim',
            'NOVO-1;Bateria;Desc;peca;Baterias;Samsung;;sim;30,00;80,00;3;1;;;;sim',
            'NOVO-1;Bateria repetida;Desc;peca;Baterias;Samsung;;sim;30,00;80,00;3;1;;;;sim',
        ]);

        $preview = $this->upload('preview', $csv)->json();
        $this->assertSame(1, $preview['summary']['new']);
        $this->assertSame(2, $preview['summary']['duplicates']);

        $this->upload('store', $csv)->assertOk()->assertJsonPath('summary.imported', 1);

        $existing->refresh();
        $this->assertSame(50.0, (float) $existing->cost_price);
        $this->assertSame(90.0, (float) $existing->sale_price);
        $this->assertSame(7, (int) $existing->quantity);
        $this->assertSame(0, PartMovement::query()->where('part_id', $existing->id)->count());
        $this->assertSame(3, (int) Part::query()->where('reference_number', 'NOVO-1')->sole()->quantity);
    }

    public function test_malformed_rows_and_dangerous_content_are_reported_with_line_numbers(): void
    {
        $csv = $this->csv([
            'OK-1;Válido;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;0;;;;sim',
            'RUIM-1;Faltam colunas;Desc',
            'RUIM-2;=HYPERLINK("http://x");Desc;peca;Cat;Fab;;sim;10,00;20,00;1;0;;;;sim',
            'RUIM-3;Tipo errado;Desc;servico;Cat;Fab;;talvez;-1,00;abc;2,5;0;;123;99;sim',
            ';Sem código;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;0;;;;sim',
        ]);

        $preview = $this->upload('preview', $csv)->assertOk()->json();
        $this->assertSame(1, $preview['summary']['new']);
        $this->assertSame(4, $preview['summary']['errors']);

        $byLine = collect($preview['rows'])->keyBy('line');
        $this->assertSame('error', $byLine[3]['status']);
        $this->assertStringContainsString('colunas', implode(' ', $byLine[3]['messages']));
        $this->assertStringContainsString('fórmulas', implode(' ', $byLine[4]['messages']));
        $messages = implode(' ', $byLine[5]['messages']);
        foreach (['tipo:', 'vendavel:', 'preco_custo:', 'preco_venda:', 'estoque_inicial:', 'ncm:', 'cfop:'] as $expected) {
            $this->assertStringContainsString($expected, $messages);
        }
        $this->assertStringContainsString('codigo: obrigatório', implode(' ', $byLine[6]['messages']));

        // Importação parcial: só a linha válida entra; o relatório traz as demais.
        $result = $this->upload('store', $csv)->assertOk()->json();
        $this->assertSame(1, $result['summary']['imported']);
        $this->assertSame(['OK-1'], Part::query()->pluck('reference_number')->all());
    }

    public function test_windows_1252_file_is_converted(): void
    {
        // Excel em português salva "CSV (separado por ponto e vírgula)" em Windows-1252, sem BOM.
        $csv = mb_convert_encoding(self::HEADER."\r\nACENTO-1;Conector de força;Descrição;peca;Peças;Fabricante;;sim;5,00;9,00;1;0;;;;sim\r\n", 'Windows-1252', 'UTF-8');

        $this->upload('store', $csv)->assertOk();

        $this->assertSame('Conector de força', Part::query()->sole()->name);
    }

    public function test_size_and_type_limits(): void
    {
        $this->post(route('app.parts.import.preview'), ['arquivo' => UploadedFile::fake()->create('produtos.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('arquivo');
        $this->post(route('app.parts.import.preview'), ['arquivo' => UploadedFile::fake()->create('produtos.csv', 3000, 'text/csv')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('arquivo');
    }

    public function test_technician_cannot_download_template_or_import(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN]);
        $this->actingAs($technician);

        // Requisição web negada: o app redireciona (handler global); o CSV nunca é entregue.
        $template = $this->get(route('app.parts.import.template'));
        $this->assertNotSame(200, $template->getStatusCode());
        $this->assertStringNotContainsString('codigo;nome', (string) $template->getContent());
        $this->upload('store', $this->csv(['X-1;Nome;Desc;peca;Cat;Fab;;sim;1,00;2,00;1;0;;;;sim']))->assertForbidden();

        $this->assertSame(0, Part::withoutGlobalScopes()->count());
    }

    public function test_tenant_comes_from_authenticated_user_and_codes_are_isolated(): void
    {
        $other = Tenant::factory()->create();
        Part::factory()->forTenant($other->id)->create(['reference_number' => 'COMPARTILHADO', 'quantity' => 4]);
        $otherUser = User::factory()->forTenant($other->id)->create(['roles' => User::ROLE_ADMIN]);

        // Mesmo código existe só no outro tenant: aqui é produto novo; o outro fica intacto.
        $this->upload('store', $this->csv(['COMPARTILHADO;Peça;Desc;peca;Cat;Fab;;sim;1,00;2,00;2;0;;;;sim']))
            ->assertOk()->assertJsonPath('summary.imported', 1);

        $this->assertSame($this->tenant->id, (int) Part::query()->where('reference_number', 'COMPARTILHADO')->sole()->tenant_id);
        $this->assertSame(4, (int) Part::withoutGlobalScopes()->where('tenant_id', $other->id)->sole()->quantity);

        // O usuário do outro tenant não vê o produto importado aqui.
        $this->withSession(['tenant_id' => $other->id])->actingAs($otherUser);
        $this->assertSame(1, Part::query()->count());
    }

    public function test_import_is_atomic_when_a_write_fails(): void
    {
        Part::created(function (Part $part): void {
            if ($part->reference_number === 'FALHA-2') {
                throw new \RuntimeException('falha simulada');
            }
        });

        $this->upload('store', $this->csv([
            'FALHA-1;Primeira;Desc;peca;Cat;Fab;;sim;1,00;2,00;3;0;;;;sim',
            'FALHA-2;Segunda;Desc;peca;Cat;Fab;;sim;1,00;2,00;3;0;;;;sim',
        ]))->assertServerError();

        $this->assertSame(0, Part::withoutGlobalScopes()->count());
        $this->assertSame(0, PartMovement::withoutGlobalScopes()->count());
    }

    private function csv(array $lines): string
    {
        return "\xEF\xBB\xBF".self::HEADER."\r\n".implode("\r\n", $lines)."\r\n";
    }

    private function upload(string $action, string $content)
    {
        return $this->post(
            route('app.parts.import.'.$action),
            ['arquivo' => UploadedFile::fake()->createWithContent('produtos.csv', $content)],
            ['Accept' => 'application/json'],
        );
    }

    private function expectArquivoError($response, string $fragment): void
    {
        $response->assertStatus(422);
        $this->assertStringContainsString($fragment, (string) $response->json('errors.arquivo.0'));
    }
}
