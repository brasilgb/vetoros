<?php

declare(strict_types=1);

/**
 * Verificação de arquivos e segredos (VETOR-SEC-03).
 *
 *   php scripts/security/secret-guard.php staged          arquivos no índice (hook de pre-commit)
 *   php scripts/security/secret-guard.php tracked         todos os arquivos versionados (CI)
 *   php scripts/security/secret-guard.php files <arq...>  arquivos informados
 *
 * Sai com código 1 quando encontra problema. Nunca imprime o valor encontrado.
 */

require __DIR__.'/SecretGuard.php';

use VetorOS\Security\SecretGuard;

/** @return list<string> */
function gitList(string $command): array
{
    $output = shell_exec($command);

    return $output === null || $output === false ? [] : array_values(array_filter(explode("\0", $output), 'strlen'));
}

$mode = $argv[1] ?? 'staged';

$entries = match ($mode) {
    // Conteúdo vem do índice: é o que será commitado, não o arquivo em disco.
    'staged' => array_map(
        fn (string $path) => [$path, shell_exec('git show '.escapeshellarg(':'.$path).' 2>/dev/null') ?: null],
        gitList('git diff --cached --name-only -z --diff-filter=ACMR'),
    ),
    'tracked' => array_map(
        fn (string $path) => [$path, is_file($path) ? (file_get_contents($path) ?: null) : null],
        gitList('git ls-files -z'),
    ),
    'files' => array_map(
        fn (string $path) => [$path, is_file($path) ? (file_get_contents($path) ?: null) : null],
        array_slice($argv, 2),
    ),
    default => null,
};

if ($entries === null) {
    fwrite(STDERR, "Uso: php scripts/security/secret-guard.php [staged|tracked|files <arquivos...>]\n");
    exit(2);
}

$problems = [];
foreach ($entries as [$path, $contents]) {
    array_push($problems, ...SecretGuard::problemsFor($path, $contents));
}

if ($problems !== []) {
    fwrite(STDERR, "Bloqueado: arquivos ou valores sensíveis encontrados (valores omitidos).\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "  - {$problem}\n");
    }
    fwrite(STDERR, "Remova o arquivo do índice (git rm --cached) ou o valor, e use variáveis de ambiente fora do git.\n");
    exit(1);
}

fwrite(STDOUT, sprintf("secret-guard: %d arquivo(s) verificados, nada encontrado.\n", count($entries)));
exit(0);
