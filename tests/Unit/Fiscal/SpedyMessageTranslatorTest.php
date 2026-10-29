<?php

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Spedy\SpedyMessageTranslator;
use PHPUnit\Framework\TestCase;

class SpedyMessageTranslatorTest extends TestCase
{
    public function test_traduz_campo_e_frase_de_validacao_em_ingles(): void
    {
        $this->assertSame(
            'CPF/CNPJ do tomador: é inválido',
            SpedyMessageTranslator::translate('receiver.federalTaxNumber: is invalid'),
        );
        $this->assertSame('nome do tomador: é obrigatório', SpedyMessageTranslator::translate('receiver.name: is required'));
    }

    public function test_traduz_erros_http_em_ingles(): void
    {
        $this->assertSame('não autorizado', SpedyMessageTranslator::translate('unauthorized'));
        $this->assertSame('indisponível', SpedyMessageTranslator::translate('Service Unavailable'));
        $this->assertSame('tempo limite excedido', SpedyMessageTranslator::translate('timeout'));
        $this->assertSame('erro interno no serviço de emissão', SpedyMessageTranslator::translate('Internal Server Error'));
    }

    public function test_mantem_mensagens_ja_em_portugues(): void
    {
        $this->assertSame('NCM inexistente', SpedyMessageTranslator::translate('NCM inexistente'));
        $this->assertSame('Código de serviço inválido', SpedyMessageTranslator::translate('Código de serviço inválido'));
        $this->assertSame('CNPJ inválido', SpedyMessageTranslator::translate('CNPJ inválido'));
        $this->assertSame('SPD005 serviço de NFS-e indisponível para o município', SpedyMessageTranslator::translate('SPD005 serviço de NFS-e indisponível para o município'));
    }

    public function test_campo_desconhecido_mantem_o_nome_original(): void
    {
        $this->assertSame('foo.bar: é inválido', SpedyMessageTranslator::translate('foo.bar: is invalid'));
    }
}
