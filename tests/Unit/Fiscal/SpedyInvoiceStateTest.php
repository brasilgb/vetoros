<?php

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Spedy\SpedyInvoiceState;
use PHPUnit\Framework\TestCase;

class SpedyInvoiceStateTest extends TestCase
{
    public function test_identifica_o_codigo_de_erro_no_inicio_da_mensagem(): void
    {
        $this->assertSame('E0014', SpedyInvoiceState::errorCode('[E0014] Conjunto de Série, Número ...'));
        $this->assertNull(SpedyInvoiceState::errorCode('Mensagem sem código'));
        $this->assertNull(SpedyInvoiceState::errorCode(null));
    }

    public function test_conflito_de_numeracao_so_vale_para_o_e0014(): void
    {
        $this->assertTrue(SpedyInvoiceState::hasNumberingConflict('[E0014] DPS já existe em uma NFS-e'));
        $this->assertFalse(SpedyInvoiceState::hasNumberingConflict('[E0010] A série informada na DPS não pertence à faixa'));
        $this->assertFalse(SpedyInvoiceState::hasNumberingConflict(null));
    }

    public function test_mensagem_de_e0014_nao_sugere_reenvio(): void
    {
        $message = SpedyInvoiceState::rejectionMessage([
            'code' => 'E0014',
            'message' => 'Conjunto de Série, Número, Código do Município Emissor e CNPJ/CPF informado nesta DPS já existe',
        ]);

        $this->assertStringStartsWith('[E0014] Esta DPS', $message);
        $this->assertStringContainsString('Não reenvie', $message);
    }

    public function test_mensagem_de_e0010_explica_a_serie_de_api(): void
    {
        $message = SpedyInvoiceState::rejectionMessage([
            'code' => 'E0010',
            'message' => 'A série informada na DPS não pertence à faixa definida para o tipo de emissor utilizado para a sua emissão.',
        ]);

        $this->assertStringStartsWith('[E0010] A série enviada', $message);
        $this->assertStringContainsString('canal de API', $message);
    }

    public function test_resumo_guarda_a_dps_enviada(): void
    {
        $summary = SpedyInvoiceState::summary([
            'id' => 'f1cf9e1a',
            'status' => 'rejected',
            'rps' => ['number' => 51, 'series' => '70000'],
        ]);

        $this->assertSame(['number' => 51, 'series' => '70000'], $summary['rps']);
    }
}
