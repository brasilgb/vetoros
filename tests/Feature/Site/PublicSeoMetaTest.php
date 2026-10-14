<?php

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sem SSR, o HTML do Blade é o que crawlers e prévias de link leem: cada
 * página pública precisa do próprio título, descrição e canonical.
 */
class PublicSeoMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['app.url' => 'https://vetoros.com.br']);
    }

    public function test_each_public_page_has_its_own_title_description_and_canonical(): void
    {
        $pages = [
            '/' => ['https://vetoros.com.br', 'Sistema de ordem de serviço para assistência técnica - VetorOS', 'NFS-e'],
            '/planos' => ['https://vetoros.com.br/planos', 'Planos e teste grátis - VetorOS', 'sem cartão de crédito'],
            '/termos' => ['https://vetoros.com.br/termos', 'Termos de Uso - VetorOS', 'Termos de Uso do VetorOS'],
            '/privacidade' => ['https://vetoros.com.br/privacidade', 'Política de Privacidade - VetorOS', 'protege os dados'],
        ];

        foreach ($pages as $url => [$canonical, $title, $descriptionExcerpt]) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<link rel="canonical" href="'.$canonical.'">', $html, $url);
            $this->assertStringContainsString('<meta property="og:url" content="'.$canonical.'">', $html, $url);
            $this->assertStringContainsString('<title inertia>'.$title.'</title>', $html, $url);
            $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]*'.preg_quote($descriptionExcerpt, '/').'/u', $html, $url);
            $this->assertStringContainsString('"@type":"Organization"', $html, $url);
            $this->assertStringContainsString('<meta name="robots" content="index, follow', $html, $url);
        }
    }

    public function test_application_pages_do_not_receive_public_organization_schema(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('"@type":"Organization"', $html);
    }
}
