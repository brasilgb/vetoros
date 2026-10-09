<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fatura de manutenção</title>
</head>

<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
        Fatura do contrato nº {{ $contract->contract_number ?: $contract->id }}, competência {{ $competence }}.
    </div>
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f3f4f6;padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" role="presentation"
                    style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.06);">
                    <tr>
                        <td align="center" style="background:#0f172a;padding:30px 20px;">
                            @if (!empty($companyLogoUrl))
                                <img src="{{ $companyLogoUrl }}" alt="Logo {{ $companyName }}"
                                    style="display:block;margin:0 auto 14px auto;width:84px;max-width:84px;height:auto;">
                            @endif
                            <p style="margin:0;color:#ffffff;font-size:24px;font-weight:bold;">{{ $companyName }}</p>
                            <p style="margin:6px 0 0 0;color:#cbd5f5;font-size:13px;">Fatura de manutenção</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:36px;color:#374151;">
                            <p style="margin-top:0;font-size:15px;line-height:1.6;">Prezado(a) {{ $customerName }},</p>
                            <p style="font-size:15px;line-height:1.6;">
                                Informamos que a fatura referente ao contrato de manutenção nº
                                <strong>{{ $contract->contract_number ?: $contract->id }}</strong>, competência
                                <strong>{{ $competence }}</strong>, encontra-se disponível.
                            </p>

                            <h3 style="font-size:16px;color:#111827;margin:24px 0 8px 0;">Dados da cobrança</h3>
                            <table cellpadding="0" cellspacing="0" role="presentation" style="width:100%;font-size:14px;">
                                <tr><td style="padding:6px 0;color:#6b7280;">Empresa prestadora</td><td style="padding:6px 0;text-align:right;">{{ $companyName }}</td></tr>
                                <tr><td style="padding:6px 0;color:#6b7280;">Cliente</td><td style="padding:6px 0;text-align:right;">{{ $customerName }}</td></tr>
                                <tr><td style="padding:6px 0;color:#6b7280;">Contrato</td><td style="padding:6px 0;text-align:right;">nº {{ $contract->contract_number ?: $contract->id }}</td></tr>
                                <tr><td style="padding:6px 0;color:#6b7280;">Competência</td><td style="padding:6px 0;text-align:right;">{{ $competence }}</td></tr>
                                <tr><td style="padding:6px 0;color:#6b7280;">Valor</td><td style="padding:6px 0;text-align:right;font-weight:bold;">R$ {{ number_format((float) $receivable->total_amount, 2, ',', '.') }}</td></tr>
                                <tr><td style="padding:6px 0;color:#6b7280;">Vencimento</td><td style="padding:6px 0;text-align:right;">{{ $receivable->due_date?->format('d/m/Y') ?? '-' }}</td></tr>
                                <tr><td style="padding:6px 0;color:#6b7280;">Situação do pagamento</td><td style="padding:6px 0;text-align:right;">{{ $paymentStatus }}</td></tr>
                            </table>

                            <h3 style="font-size:16px;color:#111827;margin:24px 0 8px 0;">Nota fiscal</h3>
                            <p style="font-size:14px;line-height:1.6;">
                                A Nota Fiscal de Serviços Eletrônica correspondente foi emitida e autorizada
                                @if ($document->number)
                                    (nº {{ $document->number }}@if ($document->access_key), código de verificação {{ $document->access_key }}@endif)
                                @endif.
                            </p>
                            <p style="margin:18px 0;">
                                <a href="{{ $pdfUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:14px;margin-right:8px;">Visualizar NFS-e (PDF)</a>
                                <a href="{{ $xmlUrl }}" style="display:inline-block;background:#e5e7eb;color:#111827;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:14px;">Baixar XML da NFS-e</a>
                            </p>
                            <p style="font-size:12px;color:#6b7280;line-height:1.5;">
                                Os links são pessoais e valem até {{ $linksExpireAt->format('d/m/Y') }}. Depois disso, peça o reenvio à {{ $companyName }}.
                            </p>
                            <p style="font-size:13px;line-height:1.6;background:#fef3c7;color:#92400e;padding:12px;border-radius:6px;">
                                A emissão da nota fiscal não representa confirmação do pagamento.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
