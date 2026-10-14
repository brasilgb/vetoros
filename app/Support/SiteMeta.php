<?php

namespace App\Support;

/**
 * Metadados das páginas públicas, renderizados pelo Blade (app.blade.php).
 * Sem SSR, é o único título/descrição/canonical que crawlers e prévias de
 * link enxergam, por isso cada página precisa informar os seus.
 */
class SiteMeta
{
    public const HOME_TITLE = 'Sistema de ordem de serviço para assistência técnica';

    public static function home(): array
    {
        return self::page(
            self::HOME_TITLE,
            'Sistema de ordem de serviço para assistência técnica de celulares, informática e eletrônicos: clientes, orçamentos, estoque, financeiro, app do técnico e emissão de NF-e, NFC-e e NFS-e. Teste grátis por 14 dias.',
            '/',
        );
    }

    public static function plans(): array
    {
        return self::page(
            'Planos e teste grátis',
            'Conheça os planos do VetorOS, com usuários ilimitados e todos os recursos incluídos. Teste grátis por 14 dias, sem cartão de crédito.',
            '/planos',
        );
    }

    public static function terms(): array
    {
        return self::page('Termos de Uso', 'Termos de Uso do VetorOS, sistema de gestão para assistências técnicas.', '/termos');
    }

    public static function privacy(): array
    {
        return self::page('Política de Privacidade', 'Como o VetorOS coleta, utiliza e protege os dados da sua empresa e dos seus clientes.', '/privacidade');
    }

    private static function page(string $title, string $description, string $path): array
    {
        $base = rtrim(config('app.url', url('/')), '/');

        return [
            // Mesmo formato do template de título do Inertia (app.tsx).
            'title' => "{$title} - VetorOS",
            'description' => $description,
            'url' => $path === '/' ? $base : $base.$path,
        ];
    }
}
