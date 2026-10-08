<?php

namespace App\Services;

use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Support\PartCostPolicy;
use App\Support\TenantSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Importação de produtos/peças por CSV (VETOR-PROD-IMPORT-01).
 *
 * - O tenant vem sempre do usuário autenticado, nunca do arquivo.
 * - Produto já existente (mesmo código no tenant) ou repetido no arquivo é ignorado:
 *   nada é sobrescrito (preço e estoque existentes intactos).
 * - Estoque inicial entra pelo mesmo mecanismo do cadastro manual: produto criado com
 *   saldo zero, incremento e movimento "entrada" com o custo informado.
 * - A gravação é transacional: ou todos os registros válidos entram, ou nenhum.
 */
class PartImportService
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_ROWS = 2000;

    public const DELIMITER = ';';

    /** Linhas cujo código começa com "#" são ignoradas (ex.: a linha de exemplo do modelo). */
    public const COMMENT_PREFIX = '#';

    /** Colunas na ordem do modelo => [obrigatória, tamanho máximo para texto]. */
    public const COLUMNS = [
        'codigo' => [true, 255],
        'nome' => [true, 255],
        'descricao' => [true, 500],
        'tipo' => [true, null],
        'categoria' => [true, 255],
        'fabricante' => [true, 255],
        'compatibilidade' => [false, 500],
        'vendavel' => [true, null],
        'preco_custo' => [true, null],
        'preco_venda' => [true, null],
        'estoque_inicial' => [true, null],
        'estoque_minimo' => [true, null],
        'localizacao' => [false, 255],
        'ncm' => [false, null],
        'cfop' => [false, null],
        'ativo' => [true, null],
    ];

    private const TYPES = ['peca' => 'part', 'peça' => 'part', 'produto' => 'product'];

    private const BOOLEANS = ['sim' => true, 's' => true, '1' => true, 'nao' => false, 'não' => false, 'n' => false, '0' => false];

    /** Início de célula que planilhas interpretam como fórmula (CSV injection). */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    private const MAX_STOCK = 1_000_000;

    private const MAX_MONEY = 99_999_999.99;

    /**
     * Modelo CSV: UTF-8 com BOM, separador ";", cabeçalho + linha de exemplo (ignorada).
     */
    public function template(): string
    {
        $example = [
            '#EXEMPLO-7891234567895', 'Tela LCD Galaxy A10', 'Tela LCD com touch, cor preta', 'peca',
            'Telas', 'Samsung', 'Galaxy A10 / A10s', 'sim', '120,50', '249,90', '5', '2',
            'Prateleira B3', '85177099', '5102', 'sim',
        ];

        return "\xEF\xBB\xBF".implode(self::DELIMITER, array_keys(self::COLUMNS))."\r\n"
            .implode(self::DELIMITER, $example)."\r\n";
    }

    /**
     * Lê e valida o arquivo sem gravar nada.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array{found: int, new: int, duplicates: int, errors: int, ignored: int}}
     */
    public function preview(int $tenantId, string $content): array
    {
        $records = $this->parse($content);

        // Comparação sem diferenciar maiúsculas: no MySQL o índice único (tenant, código) já
        // trata "TL-1" e "tl-1" como o mesmo código.
        $codes = array_values(array_unique(array_filter(array_map(fn (array $r) => mb_strtolower((string) ($r['values']['codigo'] ?? '')), $records))));
        $existing = Part::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn(DB::raw('LOWER(reference_number)'), $codes)
            ->pluck('reference_number')
            ->map(fn ($code) => mb_strtolower((string) $code))
            ->flip();

        $seen = [];
        $rows = [];
        $summary = ['found' => 0, 'new' => 0, 'duplicates' => 0, 'errors' => 0, 'ignored' => 0];

        foreach ($records as $record) {
            $values = $record['values'];
            $code = (string) ($values['codigo'] ?? '');

            if (str_starts_with($code, self::COMMENT_PREFIX)) {
                $summary['ignored']++;

                continue;
            }

            $summary['found']++;
            $errors = $record['errors'] ?: $this->validate($values);
            $key = mb_strtolower($code);

            if ($errors !== []) {
                $status = 'error';
                $summary['errors']++;
            } elseif (isset($existing[$key])) {
                $status = 'duplicate';
                $errors = ['Já existe produto com este código: ignorado (nada é alterado).'];
                $summary['duplicates']++;
            } elseif (isset($seen[$key])) {
                $status = 'duplicate';
                $errors = ["Código repetido no arquivo (linha {$seen[$key]}): ignorado."];
                $summary['duplicates']++;
            } else {
                $status = 'new';
                $seen[$key] = $record['line'];
                $summary['new']++;
            }

            $rows[] = [
                'line' => $record['line'],
                'status' => $status,
                'codigo' => $code,
                'nome' => (string) ($values['nome'] ?? ''),
                'messages' => $errors,
                'data' => $status === 'new' ? $this->normalize($values) : null,
            ];
        }

        if ($summary['found'] === 0) {
            throw ValidationException::withMessages(['arquivo' => 'O arquivo só tem a linha de exemplo do modelo. Preencha os produtos a partir da linha 3.']);
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * Revalida o arquivo (nunca confia na prévia do navegador) e grava os registros novos.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array{found: int, new: int, duplicates: int, errors: int, ignored: int, imported: int}}
     */
    public function import(int $tenantId, ?int $userId, string $content): array
    {
        if ($userId === null) {
            throw ValidationException::withMessages(['arquivo' => 'Usuário não identificado.']);
        }

        try {
            return DB::transaction(fn (): array => $this->importValidRows($tenantId, $userId, $content));
        } catch (UniqueConstraintViolationException) {
            // Outra importação/cadastro gravou o mesmo código ao mesmo tempo: nada foi gravado.
            throw ValidationException::withMessages([
                'arquivo' => 'Outro cadastro com um destes códigos foi gravado durante a importação. Nada foi importado; envie o arquivo novamente.',
            ]);
        }
    }

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array{found: int, new: int, duplicates: int, errors: int, ignored: int, imported: int}}
     */
    private function importValidRows(int $tenantId, int $userId, string $content): array
    {
        // Duplicidades reavaliadas dentro da transação; o índice único (tenant, código)
        // impede duplicação mesmo com importações simultâneas.
        $result = $this->preview($tenantId, $content);
        $imported = 0;

        foreach ($result['rows'] as $row) {
            if ($row['status'] !== 'new') {
                continue;
            }

            $data = $row['data'];
            $part = Part::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'part_number' => TenantSequence::next(Part::class, 'part_number', $tenantId),
                'reference_number' => $data['reference_number'],
                'name' => $data['name'],
                'description' => $data['description'],
                'type' => $data['type'],
                'is_sellable' => $data['is_sellable'],
                'category' => $data['category'],
                'manufacturer' => $data['manufacturer'],
                'model_compatibility' => $data['model_compatibility'],
                'cost_price' => $data['cost_price'],
                'sale_price' => $data['sale_price'],
                'quantity' => 0,
                'minimum_stock_level' => $data['minimum_stock_level'],
                'location' => $data['location'],
                'ncm' => $data['ncm'],
                'cfop' => $data['cfop'],
                'status' => $data['status'],
            ]);

            // Mesmo mecanismo do cadastro manual (PartController::store): saldo só por movimento.
            if ($data['quantity'] > 0) {
                $part->increment('quantity', $data['quantity']);

                PartMovement::create([
                    'tenant_id' => $tenantId,
                    'part_id' => $part->id,
                    'user_id' => $userId,
                    'movement_type' => PartMovement::TYPE_STOCK_IN,
                    'quantity' => $data['quantity'],
                    ...PartCostPolicy::currentMovementCost($part, $data['quantity']),
                    'reason' => 'Importação CSV (estoque inicial)',
                ]);
            }

            $imported++;
        }

        $result['summary']['imported'] = $imported;

        return $result;
    }

    /**
     * @return list<array{line: int, values: array<string, string>, errors: list<string>}>
     */
    private function parse(string $content): array
    {
        if ($content === '' || trim(str_replace("\xEF\xBB\xBF", '', $content)) === '') {
            throw ValidationException::withMessages(['arquivo' => 'O arquivo está vazio.']);
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['arquivo' => 'O arquivo excede 2 MB.']);
        }

        if (str_contains($content, "\0")) {
            throw ValidationException::withMessages(['arquivo' => 'O arquivo não é um CSV de texto válido.']);
        }

        $content = $this->toUtf8($content);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, 0, self::DELIMITER, '"', '');
        $header = array_map(fn ($cell) => mb_strtolower(trim((string) $cell)), $header ?: []);
        $expected = array_keys(self::COLUMNS);

        if ($header !== $expected) {
            fclose($stream);
            $missing = array_values(array_diff($expected, $header));
            $unknown = array_values(array_diff($header, $expected));

            throw ValidationException::withMessages(['arquivo' => trim(
                'Cabeçalho inválido. Use o modelo (separador ";"). '
                .($missing ? 'Faltando: '.implode(', ', $missing).'. ' : '')
                .($unknown ? 'Desconhecidas: '.implode(', ', array_filter($unknown, 'strlen')).'.' : '')
            )]);
        }

        $records = [];
        $line = 1;

        while (($cells = fgetcsv($stream, 0, self::DELIMITER, '"', '')) !== false) {
            $line++;

            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }

            if (count($records) >= self::MAX_ROWS) {
                fclose($stream);

                throw ValidationException::withMessages(['arquivo' => 'O arquivo excede '.self::MAX_ROWS.' linhas de produtos. Divida em arquivos menores.']);
            }

            $errors = count($cells) !== count($expected)
                ? ['Quantidade de colunas diferente do cabeçalho ('.count($cells).' em vez de '.count($expected).').']
                : [];
            $values = $errors === []
                ? array_combine($expected, array_map(fn ($cell) => trim((string) $cell), $cells))
                : ['codigo' => trim((string) ($cells[0] ?? '')), 'nome' => trim((string) ($cells[1] ?? ''))];

            $records[] = ['line' => $line, 'values' => $values, 'errors' => $errors];
        }

        fclose($stream);

        if ($records === []) {
            throw ValidationException::withMessages(['arquivo' => 'O arquivo não tem produtos (só o cabeçalho).']);
        }

        return $records;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function validate(array $values): array
    {
        $errors = [];

        foreach (self::COLUMNS as $column => [$required, $maxLength]) {
            $value = $values[$column] ?? '';

            if ($required && $value === '') {
                $errors[] = "{$column}: obrigatório.";

                continue;
            }

            if ($value !== '' && $maxLength !== null) {
                if (mb_strlen($value) > $maxLength) {
                    $errors[] = "{$column}: máximo de {$maxLength} caracteres.";
                }

                if (in_array(mb_substr($value, 0, 1), self::FORMULA_PREFIXES, true)) {
                    $errors[] = "{$column}: não pode começar com =, +, -, @ ou tabulação (proteção contra fórmulas).";
                }
            }
        }

        if (($values['tipo'] ?? '') !== '' && ! isset(self::TYPES[mb_strtolower($values['tipo'])])) {
            $errors[] = 'tipo: use "peca" ou "produto".';
        }

        foreach (['vendavel', 'ativo'] as $column) {
            if (($values[$column] ?? '') !== '' && ! array_key_exists(mb_strtolower($values[$column]), self::BOOLEANS)) {
                $errors[] = "{$column}: use \"sim\" ou \"nao\".";
            }
        }

        foreach (['preco_custo', 'preco_venda'] as $column) {
            if (($values[$column] ?? '') !== '' && self::money($values[$column]) === null) {
                $errors[] = "{$column}: valor inválido (ex.: 1.234,56), maior ou igual a zero e com até 2 casas.";
            }
        }

        foreach (['estoque_inicial', 'estoque_minimo'] as $column) {
            if (($values[$column] ?? '') !== '' && self::quantity($values[$column]) === null) {
                $errors[] = "{$column}: informe um número inteiro maior ou igual a zero.";
            }
        }

        if (($values['ncm'] ?? '') !== '' && ! preg_match('/^\d{8}$/', preg_replace('/\D+/', '', $values['ncm']) ?? '')) {
            $errors[] = 'ncm: informe 8 dígitos.';
        }

        if (($values['cfop'] ?? '') !== '' && ! preg_match('/^\d{4}$/', preg_replace('/\D+/', '', $values['cfop']) ?? '')) {
            $errors[] = 'cfop: informe 4 dígitos.';
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private function normalize(array $values): array
    {
        $optional = fn (string $column) => ($values[$column] ?? '') === '' ? null : $values[$column];

        return [
            'reference_number' => $values['codigo'],
            'name' => $values['nome'],
            'description' => $values['descricao'],
            'type' => self::TYPES[mb_strtolower($values['tipo'])],
            'is_sellable' => self::BOOLEANS[mb_strtolower($values['vendavel'])],
            'category' => $values['categoria'],
            'manufacturer' => $values['fabricante'],
            'model_compatibility' => $optional('compatibilidade'),
            'cost_price' => self::money($values['preco_custo']),
            'sale_price' => self::money($values['preco_venda']),
            'quantity' => self::quantity($values['estoque_inicial']),
            'minimum_stock_level' => self::quantity($values['estoque_minimo']),
            'location' => $optional('localizacao'),
            'ncm' => $optional('ncm') === null ? null : preg_replace('/\D+/', '', $values['ncm']),
            'cfop' => $optional('cfop') === null ? null : preg_replace('/\D+/', '', $values['cfop']),
            'status' => self::BOOLEANS[mb_strtolower($values['ativo'])],
        ];
    }

    /**
     * Aceita "1.234,56", "1234,56", "1234.56", "R$ 10,00"; recusa negativo e mais de 2 casas.
     */
    public static function money(string $value): ?float
    {
        $raw = trim(str_ireplace(['R$', ' ', "\u{00A0}"], '', $value));

        if (str_contains($raw, ',')) {
            $raw = str_replace(['.', ','], ['', '.'], $raw);
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
            return null;
        }

        $amount = round((float) $raw, 2);

        return $amount <= self::MAX_MONEY ? $amount : null;
    }

    public static function quantity(string $value): ?int
    {
        $raw = str_replace('.', '', trim($value));

        if (! preg_match('/^\d+$/', $raw) || (int) $raw > self::MAX_STOCK) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Excel em português costuma salvar CSV em Windows-1252: converte para UTF-8.
     */
    private function toUtf8(string $content): string
    {
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
}
