<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nota fiscal VetorOS</title>
</head>

<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
    @php
        $logoPath = public_path('images/vetor.png');
        $logoUrl = file_exists($logoPath) ? asset('images/vetor.png') : null;
    @endphp

    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
        A nota fiscal de serviço da sua assinatura VetorOS foi autorizada.
    </div>

    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f3f4f6;padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" role="presentation"
                    style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.06);">
                    <tr>
                        <td align="center" style="background:#020817;padding:30px 20px;">
                            @if (!empty($logoUrl))
                                <img src="{{ $logoUrl }}" alt="VetorOS" style="display:block;margin:0 auto 14px auto;width:84px;max-width:84px;height:auto;">
                            @endif
                            @include('emails.partials.brand-title')
                            <p style="margin:6px 0 0 0;color:#cbd5f5;font-size:13px;">Nota fiscal de serviço</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:36px;color:#374151;">
                            <h2 style="margin-top:0;font-size:20px;color:#111827;">NFS-e nº {{ $document->number ?: $document->id }} autorizada</h2>

                            <p style="font-size:15px;line-height:1.6;margin:14px 0;">
                                Olá, {{ $tenant?->name }}. A nota fiscal de serviço da assinatura do <strong>VetorOS</strong> emitida por
                                {{ $issuer->legal_name ?: 'ABrasil Sistemas' }} está disponível.
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#f9fafb;border-radius:8px;padding:18px;margin:24px 0;font-size:14px;">
                                <tr><td style="padding:4px 0;"><strong>Tomador:</strong> {{ $tenant?->company ?: $tenant?->name }}</td></tr>
                                @if ($charge)
                                    <tr><td style="padding:4px 0;"><strong>Contrato:</strong> {{ $charge->contract->contract_number }}</td></tr>
                                @endif
                                <tr><td style="padding:4px 0;"><strong>Competência:</strong> {{ $document->reference_start?->format('d/m/Y') }} a {{ $document->reference_end?->format('d/m/Y') }}</td></tr>
                                <tr><td style="padding:4px 0;"><strong>Valor:</strong> R$ {{ number_format((float) $document->amount, 2, ',', '.') }}</td></tr>
                            </table>

                            <p style="margin:24px 0 8px 0;">
                                <a href="{{ $links['pdf'] }}" target="_blank" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:6px;font-size:14px;font-weight:bold;">Abrir PDF</a>
                                <a href="{{ $links['xml'] }}" target="_blank" style="display:inline-block;margin-left:8px;color:#2563eb;text-decoration:none;padding:12px 6px;font-size:14px;">Baixar XML</a>
                            </p>
                            <p style="font-size:12px;color:#6b7280;margin:0 0 24px 0;">Links válidos até {{ $links['expires_at']->format('d/m/Y') }}.</p>

                            <p style="font-size:14px;line-height:1.6;color:#6b7280;">
                                Em caso de dúvidas, acesse
                                <a href="https://vetoros.com.br" target="_blank" style="color:#2563eb;text-decoration:none;">VetorOS</a>
                                e use um de nossos canais de atendimento.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
