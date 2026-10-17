<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

/**
 * Recebimento das assinaturas SaaS pela Efí Bank, único gateway (VETOR-SAAS-EFI-02/03). Linha
 * única, só RootAdmin. Credenciais, certificado e segredo do webhook ficam criptografados e nunca
 * vão ao navegador. Sem homologação não há cobrança externa: nunca existe outro gateway de reserva.
 */
class SaasPaymentSetting extends Model
{
    public const PROVIDER_EFI = 'efi';

    public const METHOD_PIX = 'pix';

    public const METHOD_BILLET = 'billet';

    public const METHOD_CARD = 'card';

    public const METHOD_CARD_SUBSCRIPTION = 'card_subscription';

    public const METHODS = [
        self::METHOD_PIX => 'Pix',
        self::METHOD_BILLET => 'Boleto/Bolix',
        self::METHOD_CARD => 'Cartão de crédito (avulso)',
        self::METHOD_CARD_SUBSCRIPTION => 'Cartão de crédito (recorrente)',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['efi_charges_client_id', 'efi_charges_client_secret', 'efi_pix_client_id', 'efi_pix_client_secret', 'efi_pix_certificate', 'efi_webhook_secret'];

    protected function casts(): array
    {
        return [
            'efi_charges_client_id' => 'encrypted',
            'efi_charges_client_secret' => 'encrypted',
            'efi_pix_client_id' => 'encrypted',
            'efi_pix_client_secret' => 'encrypted',
            'efi_pix_certificate' => 'encrypted',
            'efi_webhook_secret' => 'encrypted',
            'efi_methods' => 'array',
            'efi_plans' => 'array',
            'efi_pix_certificate_expires_at' => 'datetime',
            'efi_pix_webhook_registered_at' => 'datetime',
            'efi_homologated_at' => 'datetime',
            'last_connection_test_at' => 'datetime',
            'last_connection_ok' => 'boolean',
            'last_pix_notification_at' => 'datetime',
            'last_charges_notification_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->orderBy('id')->firstOrCreate([]);
    }

    /** Novas cobranças externas só existem com a Efí homologada; sem isso ficam bloqueadas. */
    public function usesEfi(): bool
    {
        return $this->efi_homologated_at !== null;
    }

    /** Mensagem administrativa quando a cobrança externa está bloqueada (nunca aciona outro gateway). */
    public function blockedReason(): ?string
    {
        return match (true) {
            ! $this->chargesConfigured() && ! $this->pixConfigured() => 'Efí Bank não configurada: cadastre as credenciais em RootAdmin → Financeiro · Efí Bank.',
            $this->efi_homologated_at === null => 'Efí Bank não homologada: conclua a homologação em RootAdmin → Financeiro · Efí Bank.',
            $this->enabledMethods() === [] => 'Nenhum meio de pagamento habilitado na Efí Bank.',
            default => null,
        };
    }

    public function methodEnabled(string $method): bool
    {
        return in_array($method, $this->efi_methods ?? [], true);
    }

    /** @return list<string> */
    public function enabledMethods(): array
    {
        return array_values(array_intersect(array_keys(self::METHODS), $this->efi_methods ?? []));
    }

    public function isProduction(): bool
    {
        return $this->efi_environment === 'production';
    }

    public function chargesConfigured(): bool
    {
        return filled($this->efi_charges_client_id) && filled($this->efi_charges_client_secret);
    }

    public function pixConfigured(): bool
    {
        return filled($this->efi_pix_client_id) && filled($this->efi_pix_client_secret) && filled($this->efi_pix_certificate) && filled($this->efi_pix_key);
    }
}
