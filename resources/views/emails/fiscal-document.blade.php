<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nota fiscal de serviço</title>
</head>

<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
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
                            <p style="margin:6px 0 0 0;color:#cbd5f5;font-size:13px;">Nota fiscal de serviço</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:36px;color:#374151;">
                            <h2 style="margin-top:0;font-size:20px;color:#111827;">Olá, {{ $customerName }}.</h2>
                            <p style="font-size:15px;line-height:1.6;">
                                Segue a nota fiscal de serviço referente a: <strong>{{ $description }}</strong>.
                            </p>
                            <table cellpadding="0" cellspacing="0" role="presentation" style="width:100%;font-size:14px;margin:18px 0;">
                                <tr>
                                    <td style="padding:6px 0;color:#6b7280;">Número</td>
                                    <td style="padding:6px 0;text-align:right;font-weight:bold;">{{ $document->number ?: '-' }}</td>
                                </tr>
                                @if ($document->access_key)
                                    <tr>
                                        <td style="padding:6px 0;color:#6b7280;">Código de verificação</td>
                                        <td style="padding:6px 0;text-align:right;">{{ $document->access_key }}</td>
                                    </tr>
                                @endif
                                @if ($document->issued_at)
                                    <tr>
                                        <td style="padding:6px 0;color:#6b7280;">Emitida em</td>
                                        <td style="padding:6px 0;text-align:right;">{{ $document->issued_at->format('d/m/Y') }}</td>
                                    </tr>
                                @endif
                            </table>
                            <p style="font-size:14px;line-height:1.6;color:#6b7280;">O PDF e o XML da nota estão anexados a este e-mail.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
