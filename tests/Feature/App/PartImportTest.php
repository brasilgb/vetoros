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
        $this->assertSame(['found' => 2, 'new' => 2, 'duplicates' => 0, 'errors' => 0, 'ignored' => 0, 'rejected' => 0, 'normalized' => 0], $preview['summary']);
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

    // ------------------------------------------------------------ VETOR-IMPORT-CSV-02

    public function test_quantity_parser_accepts_spreadsheet_formats_and_never_rounds(): void
    {
        foreach ([
            '0' => 0, '12' => 12, ' 12 ' => 12, "\u{00A0}7\u{00A0}" => 7, "1\u{202F}234" => 1234, '1 234' => 1234,
            '1.234' => 1234, '12,0' => 12, '12,00' => 12, '12.0' => 12, '12.00' => 12, '1.234,00' => 1234, "\t5" => 5,
        ] as $input => $expected) {
            $this->assertSame([$expected, null], PartImportService::parseQuantity((string) $input), json_encode($input));
        }

        foreach ([
            '1,5' => 'casas decimais', '1.5' => 'casas decimais', '2,50' => 'casas decimais', '0,1' => 'casas decimais',
            '-1' => 'negativo', '-0' => 'negativo', '-1,5' => 'casas decimais', '-x' => 'não é um número inteiro', '1E+03' => 'notação científica', '1,5e2' => 'notação científica',
            'abc' => 'não é um número inteiro', '12 un' => 'não é um número inteiro', '1000001' => 'máximo',
        ] as $input => $reason) {
            [$value, $message] = PartImportService::parseQuantity((string) $input);
            $this->assertNull($value, (string) $input);
            $this->assertStringContainsString($reason, (string) $message, (string) $input);
        }

        // Antes, "1.5" virava 15 porque todos os pontos eram removidos.
        $this->assertNull(PartImportService::quantity('1.5'));
    }

    public function test_empty_minimum_stock_becomes_zero_and_valid_values_are_kept(): void
    {
        $csv = $this->csv([
            'EM-VAZIO;Sem mínimo;Desc;peca;Cat;Fab;;sim;10,00;20,00;3;;;;;sim',
            'EM-ZERO;Mínimo zero;Desc;peca;Cat;Fab;;sim;10,00;20,00;3;0;;;;sim',
            'EM-POS;Mínimo positivo;Desc;peca;Cat;Fab;;sim;10,00;20,00;3; 4 ;;;;sim',
            'EM-DEC0;Mínimo 2,00;Desc;peca;Cat;Fab;;sim;10,00;20,00;3;2,00;;;;sim',
        ]);

        $preview = $this->upload('preview', $csv)->assertOk()->json();
        $this->assertSame(4, $preview['summary']['new']);
        $this->assertSame(0, $preview['summary']['errors']);

        $this->upload('store', $csv)->assertOk();
        $this->assertSame(
            ['EM-DEC0' => 2, 'EM-POS' => 4, 'EM-VAZIO' => 0, 'EM-ZERO' => 0],
            Part::query()->orderBy('reference_number')->pluck('minimum_stock_level', 'reference_number')->map(fn ($v) => (int) $v)->all(),
        );
    }

    public function test_invalid_minimum_stock_reports_line_column_and_value_without_importing(): void
    {
        $lines = [];
        foreach (range(1, 45) as $i) {
            $lines[] = "P-{$i};Produto {$i};Desc;peca;Cat;Fab;;sim;10,00;20,00;1;1;;;;sim";
        }
        $lines[] = '47;Produto 47;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;1,5;;;;sim';   // linha 47 do arquivo
        $lines[] = '48;Produto 48;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;-2,5;;;;sim';  // linha 48: decimal negativo
        $lines[] = '49;Produto 49;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;dois;;;;sim';  // linha 49

        $preview = $this->upload('preview', $this->csv($lines))->assertOk()->json();
        $rows = collect($preview['rows'])->keyBy('line');

        $this->assertSame('47', $rows[47]['codigo']);
        $this->assertSame(['estoque_minimo: valor "1,5" tem casas decimais (o valor não é arredondado); informe um número inteiro maior ou igual a zero.'], $rows[47]['messages']);
        $this->assertStringContainsString('valor "-2,5" tem casas decimais', $rows[48]['messages'][0]);
        $this->assertStringContainsString('valor "dois" não é um número inteiro válido', $rows[49]['messages'][0]);
        $this->assertSame(3, $preview['summary']['errors']);

        $this->upload('store', $this->csv($lines))->assertOk();
        $this->assertSame(0, Part::query()->whereIn('reference_number', ['47', '48', '49'])->count());
        $this->assertSame(45, Part::query()->count());
    }

    public function test_line_numbers_are_physical_even_with_multiline_cells(): void
    {
        $csv = $this->csv([
            "ML-1;Produto;\"Descrição em\r\nduas linhas\";peca;Cat;Fab;;sim;10,00;20,00;1;1;;;;sim",
            'ML-2;Produto;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;1,5;;;;sim',
        ]);

        $rows = collect($this->upload('preview', $csv)->assertOk()->json('rows'))->keyBy('codigo');

        $this->assertSame(2, $rows['ML-1']['line']);
        $this->assertSame(4, $rows['ML-2']['line'], 'a linha 3 do arquivo é a continuação da descrição');
    }

    public function test_libreoffice_and_excel_exports_are_accepted(): void
    {
        // LibreOffice: UTF-8 sem BOM, aspas em todo texto, espaço estreito no milhar e casas decimais nos inteiros.
        $libre = self::HEADER."\n"
            .'"LO-1";"Tela";"Desc";"peca";"Telas";"Samsung";"";"sim";"1'."\u{202F}".'234,50";"2'."\u{202F}".'499,90";"1'."\u{202F}".'000";"2,00";"";"85177099";"5102";"sim"'."\n";
        // Excel pt-BR: Windows-1252, CRLF, NBSP vindo de célula formatada, estoque mínimo vazio.
        $excel = mb_convert_encoding(self::HEADER."\r\nXL-1;Película;Descrição;produto;Acessórios;Genérica;;nao;3,50;19,90;10"."\u{00A0}".";;;;;sim\r\n", 'Windows-1252', 'UTF-8');

        $this->upload('store', $libre)->assertOk();
        $this->upload('store', $excel)->assertOk();

        $lo = Part::query()->where('reference_number', 'LO-1')->sole();
        $this->assertSame(1000, (int) $lo->quantity);
        $this->assertSame(2, (int) $lo->minimum_stock_level);
        $this->assertSame(1234.5, (float) $lo->cost_price);
        $xl = Part::query()->where('reference_number', 'XL-1')->sole();
        $this->assertSame(10, (int) $xl->quantity);
        $this->assertSame(0, (int) $xl->minimum_stock_level);
    }

    public function test_other_numeric_fields_report_the_invalid_value(): void
    {
        $preview = $this->upload('preview', $this->csv([
            'NUM-1;Produto;Desc;peca;Cat;Fab;;sim;10,999;-5;1.5;1;;1234;51;sim',
        ]))->assertOk()->json();

        $messages = implode(' ', $preview['rows'][0]['messages']);
        $this->assertStringContainsString('preco_custo: valor "10,999" inválido', $messages);
        $this->assertStringContainsString('preco_venda: valor "-5" inválido', $messages);
        $this->assertStringContainsString('estoque_inicial: valor "1.5" tem casas decimais', $messages);
        $this->assertStringContainsString('ncm: valor "1234" inválido', $messages);
        $this->assertStringContainsString('cfop: valor "51" inválido', $messages);
        $this->assertStringNotContainsString('estoque_minimo', $messages);
    }

    public function test_template_example_is_valid_when_used_as_data(): void
    {
        $template = $this->get(route('app.parts.import.template'))->assertOk()->getContent();
        // A linha de exemplo começa com "#" (ignorada); sem o "#" ela precisa ser válida.
        $asData = str_replace('#EXEMPLO-', 'EXEMPLO-', $template);

        $preview = $this->upload('preview', $asData)->assertOk()->json();
        $this->assertSame(['found' => 1, 'new' => 1, 'duplicates' => 0, 'errors' => 0, 'ignored' => 0, 'rejected' => 0, 'normalized' => 0], $preview['summary']);
        $this->assertSame(2, $preview['rows'][0]['data']['minimum_stock_level']);
        $this->assertSame(5, $preview['rows'][0]['data']['quantity']);
    }

    // ------------------------------------------------------------ VETOR-IMPORT-CSV-02.1

    public function test_negative_minimum_stock_is_adjusted_to_zero_with_a_warning(): void
    {
        $lines = [];
        foreach (range(1, 52) as $i) {
            $lines[] = "OK-{$i};Produto {$i};Desc;peca;Cat;Fab;;sim;10,00;20,00;1;1;;;;sim";
        }
        $lines[] = '53;Menos um;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;-1;;;;sim';        // linha 54
        $lines[] = 'NEG-2;Menos dois;Desc;peca;Cat;Fab;;sim;10,00;20,00;1; -2 ;;;;sim';  // linha 55
        $lines[] = 'NEG-6;Menos seis;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;-6;;;;sim';    // linha 56
        $lines[] = 'NEG-0;Menos zero;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;-0;;;;sim';    // linha 57: já é zero
        $lines[] = 'VAZIO;Vazio;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;;;;;sim';           // linha 58
        $lines[] = 'CINCO;Cinco;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;5;;;;sim';          // linha 59
        $lines[] = 'DEZ;Dez;Desc;peca;Cat;Fab;;sim;10,00;20,00;1; 10 ;;;;sim';           // linha 60
        $lines[] = 'DEC;Decimal;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;1,5;;;;sim';        // linha 61
        $lines[] = 'TXT;Texto;Desc;peca;Cat;Fab;;sim;10,00;20,00;1;abc;;;;sim';          // linha 62
        $csv = $this->csv($lines);

        $preview = $this->upload('preview', $csv)->assertOk()->json();
        $rows = collect($preview['rows'])->keyBy('line');

        $this->assertSame('new', $rows[54]['status']);
        $this->assertSame([], $rows[54]['messages']);
        $this->assertSame(['estoque_minimo: valor -1 ajustado automaticamente para 0.'], $rows[54]['warnings']);
        $this->assertSame(['estoque_minimo: valor -2 ajustado automaticamente para 0.'], $rows[55]['warnings']);
        $this->assertSame(['estoque_minimo: valor -6 ajustado automaticamente para 0.'], $rows[56]['warnings']);
        $this->assertSame([], $rows[57]['warnings']);
        $this->assertSame([], $rows[58]['warnings']);
        $this->assertSame('error', $rows[61]['status']);
        $this->assertSame('error', $rows[62]['status']);
        $this->assertSame(['found' => 61, 'new' => 59, 'duplicates' => 0, 'errors' => 2, 'ignored' => 0, 'rejected' => 2, 'normalized' => 3], $preview['summary']);

        $result = $this->upload('store', $csv)->assertOk()->json();
        $this->assertSame(59, $result['summary']['imported']);
        $this->assertSame(2, $result['summary']['rejected']);
        $this->assertSame(3, $result['summary']['normalized']);

        $this->assertSame(
            ['53' => 0, 'CINCO' => 5, 'DEZ' => 10, 'NEG-0' => 0, 'NEG-2' => 0, 'NEG-6' => 0, 'VAZIO' => 0],
            Part::query()->whereIn('reference_number', ['53', 'NEG-2', 'NEG-6', 'NEG-0', 'VAZIO', 'CINCO', 'DEZ'])
                ->orderBy('reference_number')->pluck('minimum_stock_level', 'reference_number')->map(fn ($v) => (int) $v)->all(),
        );
        $this->assertSame(0, Part::query()->whereIn('reference_number', ['DEC', 'TXT'])->count());
    }

    public function test_only_minimum_stock_is_adjusted(): void
    {
        // Estoque inicial negativo continua recusado; custos e preços negativos também.
        $preview = $this->upload('preview', $this->csv([
            'SO-MIN;Produto;Desc;peca;Cat;Fab;;sim;-1,00;-2,00;-3;-4;;;;sim',
        ]))->assertOk()->json();

        $row = $preview['rows'][0];
        $this->assertSame('error', $row['status']);
        $messages = implode(' ', $row['messages']);
        $this->assertStringContainsString('preco_custo: valor "-1,00" inválido', $messages);
        $this->assertStringContainsString('preco_venda: valor "-2,00" inválido', $messages);
        $this->assertStringContainsString('estoque_inicial: valor "-3" não pode ser negativo', $messages);
        $this->assertStringNotContainsString('estoque_minimo', $messages);
        // Linha recusada não conta valor normalizado: nada dela será gravado.
        $this->assertSame([], $row['warnings']);
        $this->assertSame(0, $preview['summary']['normalized']);
    }

    public function test_preview_and_import_report_the_same_rows(): void
    {
        $csv = $this->csv([
            'IG-1;Negativo;Desc;peca;Cat;Fab;;sim;1,00;2,00;1;-3;;;;sim',
            'IG-2;Decimal;Desc;peca;Cat;Fab;;sim;1,00;2,00;1;0,5;;;;sim',
            'IG-3;Normal;Desc;peca;Cat;Fab;;sim;1,00;2,00;1;2;;;;sim',
        ]);

        $preview = $this->upload('preview', $csv)->assertOk()->json();
        $result = $this->upload('store', $csv)->assertOk()->json();

        $this->assertSame($preview['rows'], $result['rows']);
        $this->assertSame($preview['summary'], array_diff_key($result['summary'], ['imported' => true]));
        $this->assertSame(2, $result['summary']['imported']);
    }

    public function test_spreadsheet_exports_with_negative_minimum_stock(): void
    {
        // LibreOffice: UTF-8, aspas, NBSP antes do sinal. Excel: Windows-1252, CRLF.
        $libre = self::HEADER."\n".'"LO-NEG";"Tela";"Desc";"peca";"Telas";"Samsung";"";"sim";"10,00";"20,00";"1";"'."\u{00A0}".'-1";"";"";"";"sim"'."\n";
        $excel = mb_convert_encoding(self::HEADER."\r\nXL-NEG;Película;Descrição;produto;Acessórios;Genérica;;nao;3,50;19,90;10;-2;;;;sim\r\n", 'Windows-1252', 'UTF-8');

        $this->assertSame(['estoque_minimo: valor -1 ajustado automaticamente para 0.'], $this->upload('store', $libre)->assertOk()->json('rows.0.warnings'));
        $this->assertSame(['estoque_minimo: valor -2 ajustado automaticamente para 0.'], $this->upload('store', $excel)->assertOk()->json('rows.0.warnings'));
        $this->assertSame(0, (int) Part::query()->where('reference_number', 'LO-NEG')->sole()->minimum_stock_level);
        $this->assertSame(0, (int) Part::query()->where('reference_number', 'XL-NEG')->sole()->minimum_stock_level);
    }

    public function test_adjustment_respects_tenant_isolation(): void
    {
        $other = Tenant::factory()->create();
        Part::factory()->forTenant($other->id)->create(['reference_number' => 'ISO-1', 'minimum_stock_level' => 7]);

        $result = $this->upload('store', $this->csv(['ISO-1;Peça;Desc;peca;Cat;Fab;;sim;1,00;2,00;2;-5;;;;sim']))->assertOk()->json();

        $this->assertSame(1, $result['summary']['imported']);
        $this->assertSame(1, $result['summary']['normalized']);
        $this->assertSame(0, (int) Part::query()->where('reference_number', 'ISO-1')->sole()->minimum_stock_level);
        $this->assertSame(7, (int) Part::withoutGlobalScopes()->where('tenant_id', $other->id)->sole()->minimum_stock_level);
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
