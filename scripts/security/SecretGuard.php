<?php

declare(strict_types=1);

namespace VetorOS\Security;

/**
 * Bloqueio de arquivos e valores sensíveis antes do commit e no CI (VETOR-SEC-03).
 *
 * Sem dependência do Laravel: roda no hook de pre-commit, no CI e nos testes. Os relatórios
 * citam só o arquivo, a linha e o nome da regra ou da variável, nunca o valor encontrado.
 */
final class SecretGuard
{
    /** Arquivos que nunca devem ser versionados: dumps, backups, bancos locais, certificados, .env reais. */
    private const FORBIDDEN_PATHS = [
        'dump ou backup de banco' => '~(?:^|/)(?:backups?/.+|[^/]+\.(?:sql|sql\.gz|dump|bak|sqlite|sqlite3|db))$~i',
        'certificado ou chave privada' => '~(?:^|/)[^/]+\.(?:pfx|p12|pem|key)$~i',
        'arquivo .env real' => '~(?:^|/)\.env(?:\.(?!example$)[A-Za-z0-9_-]+)?$~',
    ];

    /** Formatos de credencial com alta confiança (mesmos do .gitleaks.toml do projeto). */
    private const SECRET_PATTERNS = [
        'credencial do Mercado Pago' => '~\b(?:APP_USR|TEST)-[0-9A-Za-z][0-9A-Za-z-]{30,}\b~',
        'APP_KEY do Laravel' => '~base64:[A-Za-z0-9+/]{43}=~',
        'chave privada' => '~-----BEGIN [A-Z ]*PRIVATE KEY-----~',
        'chave de acesso AWS' => '~\bAKIA[0-9A-Z]{16}\b~',
        'chave de API Google' => '~\bAIza[0-9A-Za-z_-]{35}\b~',
        'token do GitHub' => '~\bgh[pousr]_[A-Za-z0-9]{36}\b~',
    ];

    /** Nome de variável de ambiente considerado sensível. */
    private const SENSITIVE_NAME = '~(?:KEY|TOKEN|SECRET|PASSWORD|PASSWD|PRIVATE|CREDENTIAL)~';

    /** Valores aceitos em variável sensível de exemplo: vazio, null e referência a outra variável. */
    private const PLACEHOLDER = '~^(?:|null|"\s*"|\'\s*\'|"?\$\{[A-Z0-9_]+\}"?)$~i';

    private const MAX_SCANNED_BYTES = 2 * 1024 * 1024;

    public static function forbiddenPathReason(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        foreach (self::FORBIDDEN_PATHS as $reason => $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Variáveis sensíveis com valor preenchido em um arquivo no formato .env.
     *
     * @return list<array{line: int, name: string}>
     */
    public static function filledSensitiveVariables(string $contents): array
    {
        $found = [];

        foreach (preg_split('~\R~', $contents) ?: [] as $index => $line) {
            if (preg_match('~^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*?)\s*$~', $line, $match) !== 1) {
                continue;
            }

            $value = preg_replace('~\s+#.*$~', '', $match[2]) ?? $match[2];

            if (preg_match(self::SENSITIVE_NAME, $match[1]) === 1 && preg_match(self::PLACEHOLDER, trim($value)) !== 1) {
                $found[] = ['line' => $index + 1, 'name' => $match[1]];
            }
        }

        return $found;
    }

    /**
     * Credenciais reconhecidas no conteúdo (nome do formato e linha, sem o valor).
     *
     * @return list<array{line: int, name: string}>
     */
    public static function secretsInContents(string $contents): array
    {
        if (strlen($contents) > self::MAX_SCANNED_BYTES || str_contains(substr($contents, 0, 8000), "\0")) {
            return [];
        }

        $found = [];

        foreach (preg_split('~\R~', $contents) ?: [] as $index => $line) {
            if (str_contains($line, 'gitleaks:allow')) {
                continue;
            }

            foreach (self::SECRET_PATTERNS as $name => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = ['line' => $index + 1, 'name' => $name];
                }
            }
        }

        return $found;
    }

    /**
     * Todos os problemas de um arquivo (caminho proibido, .env.example preenchido, credenciais).
     *
     * @return list<string>
     */
    public static function problemsFor(string $path, ?string $contents): array
    {
        $problems = [];

        if (($reason = self::forbiddenPathReason($path)) !== null) {
            $problems[] = "{$path}: {$reason} não pode ser versionado";
        }

        if ($contents === null) {
            return $problems;
        }

        if (preg_match('~(?:^|/)\.env\.example$~', $path) === 1) {
            foreach (self::filledSensitiveVariables($contents) as $variable) {
                $problems[] = "{$path}:{$variable['line']}: {$variable['name']} deve ficar vazia no exemplo";
            }
        }

        foreach (self::secretsInContents($contents) as $secret) {
            $problems[] = "{$path}:{$secret['line']}: possível {$secret['name']}";
        }

        return $problems;
    }
}
