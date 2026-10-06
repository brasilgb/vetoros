<?php

namespace App\Services\Fiscal\Spedy;

use App\Models\App\Company;
use App\Models\App\Customer;
use App\Models\App\FiscalSetting;
use App\Models\App\Order;
use App\Models\App\OrderItem;
use App\Models\App\Sale;
use App\Models\Tenant;
use App\Services\Fiscal\FiscalValidationException;
use Illuminate\Support\Str;

/**
 * Traduz os registros do VetorOS para os DTOs da API da Spedy, no modo "nota
 * completa": a tributação vai no payload e o tenant nunca precisa acessar o
 * painel da Spedy. Os valores tributários vêm das configurações fiscais do
 * tenant e do cadastro de cada peça; devem ser validados pela contabilidade.
 */
class SpedyPayloadBuilder
{
    private const TAX_REGIMES = [
        '1' => 'simplesNacional',
        '2' => 'simplesNacionalExcessoSublimite',
        '3' => 'regimeNormal',
        '4' => 'simplesNacionalMEI',
    ];

    private const PAYMENT_METHODS = [
        'pix' => 'pix',
        'cartao' => 'creditCard',
        'dinheiro' => 'money',
        'transferencia' => 'bankTransfer',
        'boleto' => 'billetBanking',
    ];

    public function company(Company $company, Tenant $tenant, FiscalSetting $setting): array
    {
        $problems = [];
        $cnpj = $this->digits($company->cnpj ?: $tenant->cnpj);
        $legalName = trim((string) ($company->companyname ?: $tenant->company ?: $tenant->name));
        $address = $this->companyAddress($company, $tenant);

        if (strlen($cnpj) !== 14) {
            $problems[] = 'Informe o CNPJ da empresa (14 dígitos) em Dados da empresa.';
        }
        if ($legalName === '') {
            $problems[] = 'Informe a razão social em Dados da empresa.';
        }
        foreach (['street' => 'logradouro', 'number' => 'número', 'district' => 'bairro', 'postalCode' => 'CEP'] as $key => $label) {
            if (blank($address[$key] ?? null)) {
                $problems[] = "Informe o {$label} da empresa em Dados da empresa.";
            }
        }
        if (! isset(self::TAX_REGIMES[(string) $setting->company_tax_regime])) {
            $problems[] = 'Selecione o regime tributário nas configurações fiscais.';
        }

        $this->guard($problems);

        return array_filter([
            'name' => Str::limit(trim((string) ($company->shortname ?: $legalName)), 80, ''),
            'legalName' => Str::limit($legalName, 80, ''),
            'federalTaxNumber' => $cnpj,
            'stateTaxNumber' => $this->digits($setting->state_registration) ?: null,
            'cityTaxNumber' => $this->digits($setting->municipal_registration) ?: null,
            'email' => $company->email ?: $tenant->email ?: null,
            'phone' => $this->digits($company->telephone ?: $tenant->phone) ?: null,
            'mobilePhone' => $this->digits($company->whatsapp ?: $tenant->whatsapp) ?: null,
            'address' => $address,
            'taxRegime' => self::TAX_REGIMES[(string) $setting->company_tax_regime],
        ], fn ($value) => $value !== null);
    }

    public function companySettings(FiscalSetting $setting): array
    {
        $production = $setting->isProductionEnvironment();
        $sefazEnvironment = $production ? 'production' : 'development';

        $general = [
            'allowDuplicateFederalTaxNumbers' => true,
            'allowMultipleInvoiceModelsPerOrder' => false,
            'decimalPrecision' => 2,
        ];

        $responsible = array_filter((array) config('services.spedy.technical_responsible'));
        if (count($responsible) === 4) {
            // Omitir remove o responsável cadastrado, por isso é sempre reenviado.
            $general['technicalResponsible'] = [
                'federalTaxNumber' => $this->digits($responsible['federal_tax_number']),
                'contactName' => $responsible['contact_name'],
                'email' => $responsible['email'],
                'phone' => $this->digits($responsible['phone']),
            ];
        }

        $settings = [
            'general' => $general,
            'productInvoice' => array_filter([
                'series' => $setting->default_nfe_series ?: '1',
                'environmentType' => $sefazEnvironment,
            ]),
            // NFS-e: em homologação usamos a Simulação da Spedy, pois a maioria das
            // prefeituras não oferece homologação funcional.
            'serviceInvoice' => array_filter([
                'series' => $setting->default_nfse_series ?: '1',
                'environmentType' => $production ? 'production' : 'simulation',
                'issueType' => $setting->nfse_mode === 'municipal' ? 'normal' : 'annfs',
            ]),
        ];

        $settings['consumerInvoice'] = array_filter([
            'series' => $setting->default_nfce_series ?: '1',
            'environmentType' => $sefazEnvironment,
            'tokenId' => $setting->nfce_csc_id ?: null,
            'csc' => $setting->nfce_csc ?: null,
        ]);

        return $settings;
    }

    public function productInvoice(Sale $sale, Company $company, FiscalSetting $setting, string $integrationId): array
    {
        $sale->loadMissing(['items.part', 'customer']);
        $customer = $sale->customer;
        $problems = [];

        if (! $customer) {
            $problems[] = 'A NF-e exige um cliente identificado na venda. Para consumidor final no balcão, emita NFC-e.';
        } else {
            $document = $this->digits($customer->cpfcnpj);
            if (! in_array(strlen($document), [11, 14], true)) {
                $problems[] = 'Informe o CPF ou CNPJ do cliente.';
            }
            foreach (['zipcode' => 'CEP', 'state' => 'UF', 'city' => 'cidade', 'district' => 'bairro', 'street' => 'logradouro', 'number' => 'número'] as $field => $label) {
                if (blank($customer->{$field})) {
                    $problems[] = "Informe o {$label} do endereço do cliente.";
                }
            }
        }

        $interstate = $customer && filled($customer->state) && filled($company->state)
            && Str::upper(trim($customer->state)) !== Str::upper(trim($company->state));

        $items = $this->saleItems($sale, $setting, $problems, $interstate);
        $this->guard($problems);

        $isCompany = strlen($this->digits($customer->cpfcnpj)) === 14;

        return [
            'integrationId' => $integrationId,
            'operationNature' => 'Venda de mercadoria',
            'operationType' => 'outgoing',
            'purposeType' => 'normal',
            'destination' => $interstate ? 'interstate' : 'internal',
            'presenceType' => 'presence',
            'isFinalCustomer' => ! $isCompany,
            'sendEmailToCustomer' => filled($customer->email),
            'receiver' => $this->receiver($customer, withAddress: true),
            'payments' => $this->payments($sale),
            'items' => $items,
        ];
    }

    public function consumerInvoice(Sale $sale, FiscalSetting $setting, string $integrationId): array
    {
        $sale->loadMissing(['items.part', 'customer']);
        $problems = [];
        $items = $this->saleItems($sale, $setting, $problems, false);
        $this->guard($problems);

        $payload = [
            'integrationId' => $integrationId,
            'operationNature' => 'Venda de mercadoria',
            'presenceType' => 'presence',
            'isFinalCustomer' => true,
            'payments' => $this->payments($sale),
            'items' => $items,
        ];

        // Consumidor identificado só quando há CPF/CNPJ válido; senão, NFC-e sem destinatário.
        $customer = $sale->customer;
        if ($customer && in_array(strlen($this->digits($customer->cpfcnpj)), [11, 14], true)) {
            $payload['receiver'] = array_filter([
                'name' => $customer->name,
                'federalTaxNumber' => $this->digits($customer->cpfcnpj),
            ]);
        }

        return $payload;
    }

    public function serviceInvoice(Order $order, FiscalSetting $setting, string $integrationId): array
    {
        $order->loadMissing(['customer', 'orderItems']);
        $customer = $order->customer;
        $problems = [];

        $serviceItems = $order->orderItems->where('item_type', OrderItem::TYPE_SERVICE);
        $amount = round($serviceItems->isNotEmpty()
            ? (float) $serviceItems->sum('total_price')
            : (float) ($order->service_value ?? 0), 2);

        if ($amount <= 0) {
            $problems[] = 'A ordem não possui valor de serviço para a NFS-e.';
        }
        if (! $customer) {
            $problems[] = 'A ordem precisa de um cliente (tomador) para a NFS-e.';
        } elseif (! in_array(strlen($this->digits($customer->cpfcnpj)), [11, 14], true)) {
            $problems[] = 'Informe o CPF ou CNPJ do cliente (tomador).';
        }
        if (blank($setting->service_list_item)) {
            $problems[] = 'Informe o item da lista de serviços (LC 116) nas configurações fiscais.';
        }
        if ($setting->default_iss_rate === null) {
            $problems[] = 'Informe a alíquota de ISS nas configurações fiscais.';
        }
        if (blank($setting->nfse_taxation_type)) {
            $problems[] = 'Informe o tipo de tributação da NFS-e nas configurações fiscais.';
        }

        $description = $this->serviceDescription($order, $serviceItems);
        if ($description === '') {
            $problems[] = 'Descreva os serviços prestados na ordem.';
        }

        $this->guard($problems);

        $payload = [
            'integrationId' => $integrationId,
            'description' => $description,
            'federalServiceCode' => trim((string) $setting->service_list_item),
            'taxationType' => $setting->nfse_taxation_type,
            'sendEmailToCustomer' => filled($customer->email),
            'receiver' => $this->receiver($customer, withAddress: filled($customer->zipcode) && filled($customer->street)),
            'total' => [
                'invoiceAmount' => $amount,
                'issRate' => (float) $setting->default_iss_rate,
            ],
        ];

        if (filled($setting->service_city_code)) {
            $payload['location'] = ['code' => (int) $this->digits($setting->service_city_code)];
        }

        return $payload;
    }

    /** CSTs de ICMS (Regime Normal) sem destaque de imposto: os únicos suportados sem base/alíquota. */
    private const ICMS_CST_WITHOUT_TAX = [40, 41, 50, 60];

    /** CSTs de PIS/COFINS que exigem base e alíquota, não informadas no VetorOS. */
    private const PIS_COFINS_CST_WITH_RATE = [1, 2, 3];

    /**
     * Itens da NF-e/NFC-e. Nenhum valor tributário é presumido: CFOP e NCM vêm
     * de cada peça e ICMS/PIS/COFINS/unidade das configurações confirmadas pelo
     * tenant; faltando algo, a emissão é bloqueada com a lista do que corrigir.
     */
    private function saleItems(Sale $sale, FiscalSetting $setting, array &$problems, bool $interstate): array
    {
        $simples = in_array((string) $setting->company_tax_regime, ['1', '2', '4'], true);
        $items = [];

        if ($sale->items->isEmpty()) {
            $problems[] = 'A venda não possui itens.';
        }

        $unit = trim((string) $setting->default_commercial_unit);
        $origin = trim((string) $setting->default_icms_origin);
        $icmsSituation = trim((string) $setting->default_icms_situation);
        $pis = trim((string) $setting->default_pis_situation);
        $cofins = trim((string) $setting->default_cofins_situation);

        foreach (['unidade comercial' => $unit, 'origem do ICMS' => $origin, $simples ? 'CSOSN' : 'CST do ICMS' => $icmsSituation, 'CST do PIS' => $pis, 'CST do COFINS' => $cofins] as $label => $value) {
            if ($value === '') {
                $problems[] = "Informe a {$label} nas configurações fiscais.";
            }
        }

        if (! $simples && $icmsSituation !== '' && ! in_array((int) $icmsSituation, self::ICMS_CST_WITHOUT_TAX, true)) {
            $problems[] = "CST de ICMS {$icmsSituation} exige base e alíquota, ainda não suportadas na emissão automática (use 40, 41, 50 ou 60, ou emita pelo registro manual).";
        }
        if (! $simples && (in_array((int) $pis, self::PIS_COFINS_CST_WITH_RATE, true) || in_array((int) $cofins, self::PIS_COFINS_CST_WITH_RATE, true))) {
            $problems[] = 'CST de PIS/COFINS com alíquota ainda não é suportado na emissão automática.';
        }

        $gross = round((float) $sale->items->sum(fn ($item) => round((float) $item->quantity * round((float) $item->unit_price, 2), 2)), 2);
        $total = round((float) $sale->total_amount, 2);
        $discount = round($gross - $total, 2);

        if ($discount < 0) {
            $problems[] = 'O total da venda é maior que a soma dos itens; acréscimos não são suportados na emissão automática.';
        }

        $discounts = $this->distributeDiscount($sale->items->all(), max(0, $discount), $gross);

        foreach ($sale->items->values() as $index => $item) {
            $part = $item->part;
            $name = $part?->name ?: 'Item '.$item->id;
            $ncm = $this->digits($part?->ncm);
            $cfop = $this->digits($part?->cfop);

            if (strlen($ncm) !== 8) {
                $problems[] = "Informe o NCM (8 dígitos) da peça/produto \"{$name}\".";
            }
            if (strlen($cfop) !== 4) {
                $problems[] = "Informe o CFOP (4 dígitos) da peça/produto \"{$name}\".";
            }
            if ($interstate && str_starts_with($cfop, '5')) {
                // Mesma operação, destinatário em outra UF: CFOP 5xxx → 6xxx.
                $cfop = '6'.substr($cfop, 1);
            }

            $quantity = (float) $item->quantity;
            $unitAmount = round((float) $item->unit_price, 2);

            $icms = ['origin' => (int) $origin];
            $icms[$simples ? 'csosn' : 'cst'] = (int) $icmsSituation;

            $items[] = array_filter([
                'code' => (string) ($part?->part_number ?: $part?->reference_number ?: $part?->id ?: $item->id),
                'description' => Str::limit($name, 120, ''),
                'ncm' => $ncm,
                'cfop' => (int) $cfop,
                'unit' => $unit,
                'quantity' => $quantity,
                'unitAmount' => $unitAmount,
                'totalAmount' => round($quantity * $unitAmount, 2),
                'discountAmount' => $discounts[$index] > 0 ? $discounts[$index] : null,
                // Trio tributável espelhado: sem ele a SEFAZ rejeita (630) ou a Spedy recusa (SPD003).
                'unitTax' => trim((string) $setting->default_tax_unit) ?: $unit,
                'quantityTax' => $quantity,
                'unitTaxAmount' => $unitAmount,
                'taxes' => [
                    'icms' => $icms,
                    'pis' => ['cst' => (int) $pis],
                    'cofins' => ['cst' => (int) $cofins],
                ],
            ], fn ($value) => $value !== null);
        }

        return $items;
    }

    /**
     * Rateia o desconto da venda (total menor que a soma dos itens) proporcionalmente
     * ao valor de cada item; o resíduo de arredondamento vai para o último item.
     *
     * @return list<float>
     */
    private function distributeDiscount(array $items, float $discount, float $gross): array
    {
        $shares = [];
        $remaining = $discount;
        $last = count($items) - 1;

        foreach (array_values($items) as $index => $item) {
            if ($discount <= 0 || $gross <= 0) {
                $shares[] = 0.0;

                continue;
            }

            $itemTotal = round((float) $item->quantity * round((float) $item->unit_price, 2), 2);
            $share = $index === $last ? round($remaining, 2) : round($discount * $itemTotal / $gross, 2);
            $remaining = round($remaining - $share, 2);
            $shares[] = $share;
        }

        return $shares;
    }

    private function payments(Sale $sale): array
    {
        return [[
            'method' => self::PAYMENT_METHODS[$sale->payment_method] ?? 'other',
            'amount' => round((float) $sale->total_amount, 2),
        ]];
    }

    private function receiver(Customer $customer, bool $withAddress): array
    {
        $receiver = array_filter([
            'name' => Str::limit(trim((string) $customer->name), 60, ''),
            'federalTaxNumber' => $this->digits($customer->cpfcnpj),
            'email' => $customer->email ?: null,
            'phoneNumber' => $this->digits($customer->phone ?: $customer->whatsapp) ?: null,
        ]);

        if ($withAddress) {
            $receiver['address'] = array_filter([
                'street' => Str::limit((string) $customer->street, 100, ''),
                'number' => Str::limit((string) ($customer->number ?: 'S/N'), 10, ''),
                'district' => Str::limit((string) $customer->district, 100, ''),
                'postalCode' => $this->digits($customer->zipcode),
                'additionalInformation' => $customer->complement ? Str::limit($customer->complement, 150, '') : null,
                'city' => array_filter(['name' => $customer->city, 'state' => Str::upper((string) $customer->state)]),
            ]);
        }

        return $receiver;
    }

    private function companyAddress(Company $company, Tenant $tenant): array
    {
        $pick = fn (string $companyField, string $tenantField) => trim((string) ($company->{$companyField} ?: $tenant->{$tenantField}));

        return array_filter([
            'street' => Str::limit($pick('street', 'street'), 100, ''),
            'number' => Str::limit($pick('number', 'number'), 10, ''),
            'district' => Str::limit($pick('district', 'district'), 100, ''),
            'postalCode' => $this->digits($pick('zip_code', 'zip_code')),
            'additionalInformation' => $pick('complement', 'complement') ?: null,
            'city' => array_filter([
                'name' => $pick('city', 'city') ?: null,
                'state' => Str::lower($pick('state', 'state')) ?: null,
            ]),
        ]);
    }

    private function serviceDescription(Order $order, $serviceItems): string
    {
        $lines = $serviceItems->pluck('description')->filter()->map(fn ($d) => trim((string) $d))->all();

        if (filled($order->services_performed)) {
            $lines[] = trim((string) $order->services_performed);
        }

        $description = trim(implode('; ', array_unique($lines)));

        return $description === '' ? '' : Str::limit("OS {$order->order_number}: {$description}", 2000, '');
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    /** @param  list<string>  $problems */
    private function guard(array $problems): void
    {
        if ($problems !== []) {
            throw new FiscalValidationException(array_values(array_unique($problems)));
        }
    }
}
