<?php

namespace App\Services\Fiscal\Spedy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP da API v1 da Spedy (https://docs.spedy.com.br).
 *
 * Duas credenciais: a chave da empresa titular (plataforma), única que gerencia
 * empresas, e a chave de cada empresa emissora (tenant), que emite apenas por ela.
 */
class SpedyClient
{
    public const MODEL_NFE = 'nfe';

    public const MODEL_NFCE = 'nfce';

    public const MODEL_NFSE = 'nfse';

    private const MODEL_PATHS = [
        self::MODEL_NFE => 'product-invoices',
        self::MODEL_NFCE => 'consumer-invoices',
        self::MODEL_NFSE => 'service-invoices',
    ];

    public function __construct(private readonly ?string $apiKey = null) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.spedy.owner_api_key'));
    }

    public static function environment(): string
    {
        return config('services.spedy.environment') === 'production' ? 'production' : 'sandbox';
    }

    public static function baseUrl(): string
    {
        return (string) config('services.spedy.base_urls.'.self::environment());
    }

    public static function forOwner(): self
    {
        $key = config('services.spedy.owner_api_key');

        if (blank($key)) {
            throw new SpedyException('Emissão fiscal nativa indisponível: integração não configurada na plataforma.');
        }

        return new self((string) $key);
    }

    public static function forCompany(?string $apiKey): self
    {
        if (blank($apiKey)) {
            throw new SpedyException('Empresa emissora ainda não cadastrada para emissão fiscal.');
        }

        return new self($apiKey);
    }

    public static function modelPath(string $model): string
    {
        return self::MODEL_PATHS[$model] ?? throw new \InvalidArgumentException("Modelo fiscal desconhecido: {$model}");
    }

    public function createCompany(array $payload): array
    {
        return $this->send('post', 'companies', $payload);
    }

    public function updateCompany(string $companyId, array $payload): array
    {
        return $this->send('put', "companies/{$companyId}", $payload);
    }

    public function updateCompanySettings(string $companyId, array $payload): array
    {
        return $this->send('put', "companies/{$companyId}/settings", $payload);
    }

    public function uploadCertificate(string $companyId, string $contents, string $filename, string $password): array
    {
        return $this->handle(
            fn () => $this->request()
                ->attach('certificateFile', $contents, $filename)
                ->post("companies/{$companyId}/certificates", ['password' => $password]),
            "companies/{$companyId}/certificates"
        );
    }

    public function createInvoice(string $model, array $payload): array
    {
        return $this->send('post', self::modelPath($model), $payload);
    }

    public function getInvoice(string $model, string $invoiceId): array
    {
        return $this->send('get', self::modelPath($model)."/{$invoiceId}");
    }

    /** Localiza a nota enviada sem resposta (timeout) pelo identificador do VetorOS. */
    public function findInvoiceByIntegrationId(string $model, string $integrationId): ?array
    {
        $result = $this->handle(
            fn () => $this->request()->get(self::modelPath($model), ['integrationId' => $integrationId, 'pageSize' => 1]),
            self::modelPath($model)
        );

        $invoice = $result['items'][0] ?? null;

        return is_array($invoice) && ($invoice['integrationId'] ?? $integrationId) === $integrationId ? $invoice : null;
    }

    public function checkInvoiceStatus(string $model, string $invoiceId): array
    {
        return $this->send('post', self::modelPath($model)."/{$invoiceId}/check-status");
    }

    public function cancelInvoice(string $model, string $invoiceId, string $reason): array
    {
        return $this->send('delete', self::modelPath($model)."/{$invoiceId}", ['reason' => $reason]);
    }

    /** PDF/XML de notas emitidas dispensam a chave na Spedy; usamos a chave mesmo assim. */
    public function downloadInvoiceFile(string $model, string $invoiceId, string $format): Response
    {
        $format = $format === 'xml' ? 'xml' : 'pdf';
        $path = self::modelPath($model)."/{$invoiceId}/{$format}";

        try {
            $response = $this->request()->get($path);
        } catch (ConnectionException) {
            throw new SpedyException('Não foi possível obter o arquivo da nota fiscal. Tente novamente em instantes.');
        }

        if (! $response->successful()) {
            throw $this->exceptionFor($response, $path);
        }

        return $response;
    }

    private function send(string $method, string $path, array $payload = []): array
    {
        return $this->handle(
            fn () => match ($method) {
                'get' => $this->request()->get($path),
                'delete' => $this->request()->send('DELETE', $path, ['json' => $payload]),
                default => $this->request()->{$method}($path, $payload),
            },
            $path
        );
    }

    private function handle(callable $call, string $path): array
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            Log::warning('Spedy: falha de conexão', ['path' => $path]);

            throw new SpedyException('Não foi possível comunicar com o serviço de emissão fiscal. Tente novamente em instantes.');
        }

        if (! $response->successful()) {
            throw $this->exceptionFor($response, $path);
        }

        return (array) ($response->json() ?? []);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::baseUrl())
            ->withHeaders(['X-Api-Key' => (string) $this->apiKey])
            ->acceptJson()
            ->timeout((int) config('services.spedy.timeout', 30))
            // 429: limite de 5 req/s por chave. Reenvio é seguro: as emissões usam integrationId.
            ->retry(3, fn (int $attempt) => 1000 * $attempt, fn ($exception) => $exception instanceof RequestException
                && $exception->response->status() === 429, throw: false);
    }

    private function exceptionFor(Response $response, string $path): SpedyException
    {
        $status = $response->status();
        $body = (array) ($response->json() ?? []);
        $errors = $this->extractErrors($body);

        Log::warning('Spedy: requisição recusada', ['path' => $path, 'status' => $status, 'errors' => $errors]);

        $message = match (true) {
            $status === 403 => 'Credencial de emissão fiscal inválida ou de outro ambiente. Contate o suporte.',
            $status === 404 => 'Registro não encontrado no serviço de emissão fiscal.',
            $status === 429 => 'Muitas solicitações ao serviço de emissão fiscal. Aguarde alguns segundos e tente novamente.',
            $status >= 500 => 'O serviço de emissão fiscal está indisponível no momento. Tente novamente em instantes.',
            $errors !== [] => 'Dados recusados pelo serviço de emissão fiscal: '.implode(' ', array_slice($errors, 0, 5)),
            default => 'Solicitação recusada pelo serviço de emissão fiscal.',
        };

        return new SpedyException($message, $status, $errors);
    }

    /** @return list<string> */
    private function extractErrors(array $body): array
    {
        $messages = [];

        foreach (['message', 'title', 'detail', 'error'] as $key) {
            if (is_string($body[$key] ?? null) && $body[$key] !== '') {
                $messages[] = $body[$key];
            }
        }

        foreach (Arr::wrap($body['errors'] ?? []) as $field => $error) {
            foreach (Arr::wrap($error) as $item) {
                $text = is_array($item) ? ($item['message'] ?? $item['description'] ?? null) : $item;

                if (is_string($text) && $text !== '') {
                    $messages[] = is_string($field) ? "{$field}: {$text}" : $text;
                }
            }
        }

        return array_values(array_unique(array_map(fn (string $m) => mb_substr(trim($m), 0, 300), $messages)));
    }
}
