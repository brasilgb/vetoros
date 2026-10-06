<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nota fiscal de serviço VetorOS</title>
</head>

<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f3f4f6;padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" role="presentation"
                    style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;">
                    <tr>
                        <td align="center" style="background:#020817;padding:30px 20px;">
                            @include('emails.partials.brand-title')
                            <p style="margin:6px 0 0 0;color:#cbd5f5;font-size:13px;">Nota fiscal de serviço</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px;color:#111827;font-size:14px;line-height:1.6;">
                            <p>Olá, {{ $document->tenant?->company ?: $document->tenant?->name }}.</p>
                            <p>Segue em anexo a NFS-e referente à sua assinatura do VetorOS.</p>
                            <table cellpadding="6" cellspacing="0" role="presentation" style="font-size:14px;">
                                <tr><td><strong>Número</strong></td><td>{{ $document->number ?: '-' }}</td></tr>
                                <tr><td><strong>Período</strong></td><td>{{ $document->reference_start?->format('d/m/Y') }} a {{ $document->reference_end?->format('d/m/Y') }}</td></tr>
                                <tr><td><strong>Valor</strong></td><td>R$ {{ number_format((float) $document->amount, 2, ',', '.') }}</td></tr>
                                <tr><td><strong>Emitida em</strong></td><td>{{ $document->issued_at?->format('d/m/Y') }}</td></tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
