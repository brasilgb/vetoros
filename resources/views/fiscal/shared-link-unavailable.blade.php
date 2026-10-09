<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Documento fiscal indisponível</title>
</head>

<body style="margin:0;padding:40px 16px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#374151;">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:10px;padding:32px;box-shadow:0 10px 25px rgba(0,0,0,0.06);">
        <h1 style="margin-top:0;font-size:20px;color:#111827;">Documento fiscal indisponível</h1>
        @if ($reason === 'expired')
            <p style="line-height:1.6;">Este link expirou ou não é válido. Peça à empresa que reenvie a fatura para receber um novo link.</p>
        @elseif ($reason === 'temporary')
            <p style="line-height:1.6;">Não foi possível obter o arquivo agora. Tente novamente em alguns minutos.</p>
        @else
            <p style="line-height:1.6;">O documento solicitado não está disponível. Fale com a empresa que enviou a fatura.</p>
        @endif
    </div>
</body>

</html>
