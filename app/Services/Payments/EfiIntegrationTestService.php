<?php

namespace App\Services\Payments;

use App\Jobs\ProcessEfiIntegrationTest;
use App\Models\Admin\EfiIntegrationTest;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\SaasPaymentEvent;
use App\Models\Admin\SaasPaymentSetting;
use App\Services\Payments\Efi\EfiChargesClient;
use App\Services\Payments\Efi\EfiException;
use App\Services\Payments\Efi\EfiPixClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cobranças de teste da integração Efí (VETOROS-EFI-TEST-01), disparadas pelo RootAdmin no modal da
 * tela Financeiro → Efí Bank. Reutiliza os clientes Pix/Cobranças, as credenciais e o ambiente já
 * configurados (nada é alterado) e os mesmos webhooks das cobranças reais.
 *
 * Isolamento: o registro fica em `efi_integration_tests` (source=integration_test), nunca em `payments`.
 * Não há tenant, contrato, assinatura, nota fiscal, financeiro ou indicador envolvido; a conciliação
 * daqui só atualiza o próprio registro de teste.
 */
class EfiIntegrationTestService
{
    /** Mínimos aplicados no VetorOS (centavos). Boleto e cartão: R$ 5,00, mínimo da API Cobranças. */
    public const MINIMUM_CENTS = [
        SaasPaymentSetting::METHOD_PIX => 1,
        SaasPaymentSetting::METHOD_BILLET => 500,
        SaasPaymentSetting::METHOD_CARD => 500,
    ];

    public const MAXIMUM_CENTS = 100000;

    private const PIX_EXPIRATION_SECONDS = 3600;

    private const BILLET_DAYS = 3;

    private const DESCRIPTION = 'Teste de integração VetorOS';

    public function __construct(
        private readonly EfiPixClient $pix,
        private readonly EfiChargesClient $charges,
    ) {}

    /** Métodos que podem ser testados com as credenciais atuais (independe de homologação). */
    public function availableMethods(): array
    {
        $settings = SaasPaymentSetting::current();

        return array_values(array_filter([
            $settings->pixConfigured() ? SaasPaymentSetting::METHOD_PIX : null,
            $settings->chargesConfigured() ? SaasPaymentSetting::METHOD_BILLET : null,
            $settings->chargesConfigured() && filled($settings->efi_payee_code) ? SaasPaymentSetting::METHOD_CARD : null,
        ]));
    }

    /**
     * Cria a cobrança de teste. Falha da Efí fica registrada no próprio teste (status `error`) com a
     * mensagem saneada da EfiException; nunca com credenciais.
     *
     * @param  array{payer?: array, card?: array, billing?: array}  $input
     */
    public function create(string $method, int $cents, array $input, ?int $userId): EfiIntegrationTest
    {
        if (! in_array($method, $this->availableMethods(), true)) {
            throw ValidationException::withMessages(['method' => 'Método sem credenciais configuradas na Efí.']);
        }

        $settings = SaasPaymentSetting::current();
        $prefix = match ($method) {
            SaasPaymentSetting::METHOD_PIX => 'VOT',
            SaasPaymentSetting::METHOD_BILLET => 'VOTB',
            default => 'VOTC',
        };

        $test = EfiIntegrationTest::query()->create([
            'method' => $method,
            'environment' => $settings->efi_environment,
            'amount' => $this->fromCents($cents),
            // txid do Pix: 26 a 35 alfanuméricos.
            'reference' => $prefix.Str::upper(Str::random(32 - strlen($prefix))),
            'status' => EfiIntegrationTest::STATUS_PENDING,
            'created_by' => $userId,
        ]);

        try {
            match ($method) {
                SaasPaymentSetting::METHOD_PIX => $this->createPix($test, $input['payer'] ?? []),
                SaasPaymentSetting::METHOD_BILLET => $this->createBillet($test, $cents, $input['payer'] ?? []),
                default => $this->createCard($test, $cents, $input),
            };
        } catch (EfiException $exception) {
            $test->forceFill(['status' => EfiIntegrationTest::STATUS_ERROR, 'error' => Str::limit($exception->getMessage(), 500, '')])->save();
        }

        FiscalAdminAudit::record('efi.integration_test_created', null, $test, [
            'source' => EfiIntegrationTest::SOURCE,
            'method' => $method,
            'environment' => $test->environment,
            'amount' => (string) $test->amount,
            'provider_id' => $test->provider_id,
            'status' => $test->status,
            'error' => $test->error,
        ]);

        // Cartão: a situação real vem da consulta, não só da resposta da criação.
        if ($method === SaasPaymentSetting::METHOD_CARD && $test->provider_id) {
            $this->refreshQuietly($test);
        }

        return $test->refresh();
    }

    /** Consulta a Efí e atualiza só o registro de teste. Idempotente. */
    public function reconcile(EfiIntegrationTest $test): EfiIntegrationTest
    {
        if (! $test->provider_id) {
            return $test;
        }

        $state = $test->method === SaasPaymentSetting::METHOD_PIX ? $this->pixState($test) : $this->chargeState($test);

        $test->forceFill([
            'status' => $state['status'],
            'provider_status' => $state['provider_status'],
            'paid_at' => $state['status'] === EfiIntegrationTest::STATUS_PAID ? ($test->paid_at ?? $state['paid_at'] ?? now()) : $test->paid_at,
            'error' => null,
            'last_checked_at' => now(),
        ])->save();

        return $test->refresh();
    }

    /** Notificação (Pix ou Cobranças) de um teste: marca o recebimento e concilia em fila. */
    public function webhookReceived(EfiIntegrationTest $test, ?SaasPaymentEvent $event = null): void
    {
        $test->forceFill(['webhook_received_at' => now(), 'webhook_count' => $test->webhook_count + 1])->save();

        ProcessEfiIntegrationTest::dispatch($test->id, $event?->id);
    }

    public function findByProviderId(string $providerId): ?EfiIntegrationTest
    {
        return $providerId === '' ? null : EfiIntegrationTest::query()->where('provider_id', $providerId)->first();
    }

    // ---- Criação -----------------------------------------------------------------

    private function createPix(EfiIntegrationTest $test, array $payer): void
    {
        $document = preg_replace('/\D/', '', (string) ($payer['cpf'] ?? ''));
        $debtor = filled($payer['name'] ?? null) && strlen($document) === 11 ? ['cpf' => $document, 'nome' => Str::limit(trim($payer['name']), 200, '')] : [];

        $cob = $this->pix->createImmediateCharge($test->reference, (string) $test->amount, $debtor, self::PIX_EXPIRATION_SECONDS, self::DESCRIPTION);
        $qr = isset($cob['loc']['id']) ? $this->pix->qrCode($cob['loc']['id']) : [];

        $test->forceFill([
            'provider_id' => $test->reference,
            'provider_status' => $cob['status'] ?? null,
            'expires_at' => now()->addSeconds(self::PIX_EXPIRATION_SECONDS),
            'details' => [
                'pix_copy_paste' => $cob['pixCopiaECola'] ?? $qr['qrcode'] ?? null,
                'pix_qr_image' => $qr['imagemQrcode'] ?? null,
            ],
        ])->save();
    }

    private function createBillet(EfiIntegrationTest $test, int $cents, array $payer): void
    {
        $expireAt = now()->startOfDay()->addDays(self::BILLET_DAYS);

        $data = $this->charges->createBillet(
            $this->items($cents),
            array_filter([
                'name' => Str::limit(trim((string) ($payer['name'] ?? '')), 255, ''),
                'cpf' => preg_replace('/\D/', '', (string) ($payer['cpf'] ?? '')),
                'email' => $payer['email'] ?? null,
            ]),
            $expireAt->toDateString(),
            $test->reference,
            app(SaasPaymentService::class)->chargesNotificationUrl(),
            self::DESCRIPTION,
        );

        $test->forceFill([
            'provider_id' => (string) $data['charge_id'],
            'provider_status' => $data['status'] ?? null,
            'expires_at' => $expireAt->copy()->endOfDay(),
            'details' => [
                'due_date' => $expireAt->toDateString(),
                'barcode' => $data['barcode'] ?? null,
                'billet_link' => $data['billet_link'] ?? $data['link'] ?? null,
                'billet_pdf' => $data['pdf']['charge'] ?? null,
                'pix_copy_paste' => $data['pix']['qrcode'] ?? null,
                'pix_qr_image' => $data['pix']['qrcode_image'] ?? null,
            ],
        ])->save();
    }

    /** O navegador só envia o `payment_token` da Efí e a máscara: número, CVV e validade nunca chegam aqui. */
    private function createCard(EfiIntegrationTest $test, int $cents, array $input): void
    {
        $card = $input['card'] ?? [];
        $payer = $input['payer'] ?? [];
        $billing = $input['billing'] ?? [];

        $data = $this->charges->createCardCharge(
            $this->items($cents),
            array_filter([
                'name' => $payer['name'] ?? null,
                'cpf' => preg_replace('/\D/', '', (string) ($payer['cpf'] ?? '')),
                'email' => $payer['email'] ?? null,
                'birth' => $payer['birth'] ?? null,
                'phone_number' => preg_replace('/\D/', '', (string) ($payer['phone_number'] ?? '')),
            ]),
            [
                'street' => $billing['street'] ?? '',
                'number' => $billing['number'] ?? '',
                'neighborhood' => $billing['neighborhood'] ?? '',
                'zipcode' => preg_replace('/\D/', '', (string) ($billing['zipcode'] ?? '')),
                'city' => $billing['city'] ?? '',
                'state' => Str::upper((string) ($billing['state'] ?? '')),
            ],
            (string) ($card['payment_token'] ?? ''),
            $test->reference,
            app(SaasPaymentService::class)->chargesNotificationUrl(),
        );

        $providerStatus = (string) ($data['status'] ?? '');
        $test->forceFill([
            'provider_id' => (string) $data['charge_id'],
            'provider_status' => $providerStatus,
            'status' => $this->mapChargeStatus($providerStatus),
            'details' => ['card_mask' => $card['card_mask'] ?? null],
        ])->save();
    }

    private function refreshQuietly(EfiIntegrationTest $test): void
    {
        try {
            $this->reconcile($test);
        } catch (EfiException $exception) {
            $test->forceFill(['error' => Str::limit($exception->getMessage(), 500, '')])->save();
        }
    }

    // ---- Leitura do provedor ---------------------------------------------------------

    /** @return array{status: string, provider_status: ?string, paid_at?: Carbon} */
    private function pixState(EfiIntegrationTest $test): array
    {
        $cob = $this->pix->getCharge($test->provider_id);
        $status = (string) ($cob['status'] ?? '');
        $received = collect($cob['pix'] ?? []);

        if ($status === 'CONCLUIDA' || $received->isNotEmpty()) {
            $refunded = $received->flatMap(fn ($pix) => $pix['devolucoes'] ?? [])->where('status', 'DEVOLVIDO')->isNotEmpty();
            $first = $received->first();

            return [
                'status' => $refunded ? EfiIntegrationTest::STATUS_REFUNDED : EfiIntegrationTest::STATUS_PAID,
                'provider_status' => $status,
                'paid_at' => isset($first['horario']) ? Carbon::parse($first['horario']) : now(),
            ];
        }

        return [
            'status' => match (true) {
                str_starts_with($status, 'REMOVIDA') => EfiIntegrationTest::STATUS_CANCELLED,
                $test->expires_at && $test->expires_at->isPast() => EfiIntegrationTest::STATUS_EXPIRED,
                default => EfiIntegrationTest::STATUS_PENDING,
            },
            'provider_status' => $status ?: null,
        ];
    }

    /** @return array{status: string, provider_status: ?string} */
    private function chargeState(EfiIntegrationTest $test): array
    {
        $status = (string) ($this->charges->getCharge($test->provider_id)['status'] ?? '');

        return ['status' => $this->mapChargeStatus($status), 'provider_status' => $status ?: null];
    }

    /** Mesma leitura de situação da API Cobranças usada na conciliação das cobranças reais. */
    private function mapChargeStatus(string $status): string
    {
        return match ($status) {
            'paid', 'settled' => EfiIntegrationTest::STATUS_PAID,
            'refunded', 'contested' => EfiIntegrationTest::STATUS_REFUNDED,
            'canceled' => EfiIntegrationTest::STATUS_CANCELLED,
            'expired' => EfiIntegrationTest::STATUS_EXPIRED,
            'unpaid' => EfiIntegrationTest::STATUS_FAILED,
            default => EfiIntegrationTest::STATUS_PENDING,
        };
    }

    private function items(int $cents): array
    {
        return [['name' => self::DESCRIPTION, 'value' => $cents, 'amount' => 1]];
    }

    private function fromCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
