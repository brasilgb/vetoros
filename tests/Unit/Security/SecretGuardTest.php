<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use VetorOS\Security\SecretGuard;

require_once dirname(__DIR__, 3).'/scripts/security/SecretGuard.php';

/**
 * VETOR-SEC-03: proteções contra novas exposições (arquivos proibidos, .env.example,
 * credenciais conhecidas, .gitignore, hook de pre-commit e configuração do gitleaks).
 *
 * As credenciais de teste são montadas em tempo de execução para que o próprio arquivo
 * não contenha nada no formato real (e não dispare os scanners do repositório).
 */
class SecretGuardTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            exec('rm -rf '.escapeshellarg($dir));
        }
        parent::tearDown();
    }

    public function test_forbidden_paths_are_detected(): void
    {
        foreach (['backup/db_backup.sql', 'backups/2026/x.txt', 'dump.sql', 'storage/x.sql.gz', 'app.sqlite', 'old.bak', 'base.dump', 'certificado.pfx', 'cert.P12', 'chave.pem', 'storage/oauth-private.key', '.env', '.env.production', 'config/.env.backup'] as $path) {
            $this->assertNotNull(SecretGuard::forbiddenPathReason($path), $path);
        }

        foreach (['.env.example', 'database/migrations/2026_10_11_100000_widen_company_identity_fields.php', 'storage/framework/.gitignore', 'resources/js/app.tsx', 'docs/sql-guide.md', 'composer.lock'] as $path) {
            $this->assertNull(SecretGuard::forbiddenPathReason($path), $path);
        }
    }

    public function test_filled_sensitive_variables_are_reported_by_name_only(): void
    {
        $contents = implode("\n", [
            'APP_NAME=VetorOS',
            'APP_KEY=',
            'DB_PASSWORD=null',
            'REDIS_PASSWORD="${DB_PASSWORD}"',
            'MAIL_PASSWORD=""',
            'MP_ACCESS_TOKEN='.$this->fakeValue(),
            'export WAHA_API_KEY="'.$this->fakeValue().'"',
            'SPEDY_WEBHOOK_SECRET='.$this->fakeValue().' # comentário',
            '# GEMINI_API_KEY='.$this->fakeValue(),
            'MAIL_HOST=smtp.example.com',
        ]);

        $this->assertSame([
            ['line' => 6, 'name' => 'MP_ACCESS_TOKEN'],
            ['line' => 7, 'name' => 'WAHA_API_KEY'],
            ['line' => 8, 'name' => 'SPEDY_WEBHOOK_SECRET'],
        ], SecretGuard::filledSensitiveVariables($contents));
    }

    public function test_known_credential_formats_are_detected(): void
    {
        $samples = [
            'credencial do Mercado Pago' => 'APP_'.'USR-'.'1234567890123456-101010-'.str_repeat('a', 32).'-123456789',
            'APP_KEY do Laravel' => 'base'.'64:'.str_repeat('A', 43).'=',
            'chave privada' => '-----BEGIN '.'RSA PRIVATE KEY-----',
            'chave de acesso AWS' => 'AK'.'IA'.str_repeat('Q', 16),
            'chave de API Google' => 'AI'.'za'.str_repeat('b', 35),
            'token do GitHub' => 'gh'.'p_'.str_repeat('c', 36),
        ];

        foreach ($samples as $name => $value) {
            $this->assertSame([['line' => 2, 'name' => $name]], SecretGuard::secretsInContents("primeira linha\nvalor = '{$value}';"), $name);
            $this->assertSame([], SecretGuard::secretsInContents("valor = '{$value}'; // gitleaks:allow"), $name);
        }

        $this->assertSame([], SecretGuard::secretsInContents("\0binário ".$samples['APP_KEY do Laravel']));
    }

    public function test_problems_never_include_the_secret_value(): void
    {
        $value = 'APP_'.'USR-'.'9876543210987654-202020-'.str_repeat('f', 32).'-987654321';
        $problems = SecretGuard::problemsFor('.env.example', "MP_ACCESS_TOKEN={$value}\n");

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('MP_ACCESS_TOKEN', implode("\n", $problems));
        $this->assertStringNotContainsString($value, implode("\n", $problems));
        $this->assertStringNotContainsString('9876543210987654', implode("\n", $problems));
    }

    public function test_repository_env_example_and_tracked_layout_are_clean(): void
    {
        $this->assertSame([], SecretGuard::problemsFor('.env.example', (string) file_get_contents($this->root.'/.env.example')));
    }

    public function test_gitignore_ignores_dumps_backups_certificates_and_real_env_files(): void
    {
        $repo = $this->tempRepo();
        copy($this->root.'/.gitignore', $repo.'/.gitignore');

        foreach (['backup/db_backup.sql', 'backups/x.tar', 'dump.sql', 'dump.sql.gz', 'base.sqlite', 'cert.pfx', 'cert.p12', 'chave.pem', '.env', '.env.production', '.env.backup'] as $path) {
            $this->assertSame(0, $this->shell(['git', 'check-ignore', '-q', '--no-index', $path], $repo)['code'], "{$path} deveria ser ignorado");
        }

        foreach (['.env.example', 'database/migrations/x.php', 'app/Models/User.php', 'storage/framework/.gitignore'] as $path) {
            $this->assertSame(1, $this->shell(['git', 'check-ignore', '-q', '--no-index', $path], $repo)['code'], "{$path} não deveria ser ignorado");
        }
    }

    public function test_pre_commit_hook_blocks_dumps_and_filled_env_example_without_printing_values(): void
    {
        $repo = $this->tempRepo();
        mkdir($repo.'/scripts/security', 0777, true);
        mkdir($repo.'/scripts/git-hooks', 0777, true);
        copy($this->root.'/scripts/security/SecretGuard.php', $repo.'/scripts/security/SecretGuard.php');
        copy($this->root.'/scripts/security/secret-guard.php', $repo.'/scripts/security/secret-guard.php');
        copy($this->root.'/scripts/git-hooks/pre-commit', $repo.'/scripts/git-hooks/pre-commit');
        chmod($repo.'/scripts/git-hooks/pre-commit', 0755);
        $this->shell(['git', 'config', 'core.hooksPath', 'scripts/git-hooks'], $repo);
        // Sem gitleaks no PATH do hook: aqui o teste cobre o secret-guard.
        $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin'];

        file_put_contents($repo.'/README.md', "ok\n");
        $this->shell(['git', 'add', 'README.md'], $repo);
        $this->assertSame(0, $this->shell(['git', 'commit', '-q', '-m', 'limpo'], $repo, $env)['code']);

        mkdir($repo.'/backup');
        file_put_contents($repo.'/backup/db.sql', "INSERT INTO customers VALUES (1);\n");
        $this->shell(['git', 'add', '-f', 'backup/db.sql'], $repo);
        $blocked = $this->shell(['git', 'commit', '-q', '-m', 'dump'], $repo, $env);
        $this->assertNotSame(0, $blocked['code']);
        $this->assertStringContainsString('backup/db.sql', $blocked['output']);
        $this->shell(['git', 'rm', '-q', '--cached', 'backup/db.sql'], $repo);

        $value = 'APP_'.'USR-'.'1111222233334444-303030-'.str_repeat('d', 32).'-111222333';
        file_put_contents($repo.'/.env.example', "APP_NAME=VetorOS\nMP_ACCESS_TOKEN={$value}\n");
        $this->shell(['git', 'add', '.env.example'], $repo);
        $blocked = $this->shell(['git', 'commit', '-q', '-m', 'env'], $repo, $env);
        $this->assertNotSame(0, $blocked['code']);
        $this->assertStringContainsString('MP_ACCESS_TOKEN', $blocked['output']);
        $this->assertStringNotContainsString($value, $blocked['output']);

        file_put_contents($repo.'/.env.example', "APP_NAME=VetorOS\nMP_ACCESS_TOKEN=\n");
        $this->shell(['git', 'add', '.env.example'], $repo);
        $this->assertSame(0, $this->shell(['git', 'commit', '-q', '-m', 'env vazio'], $repo, $env)['code']);
    }

    public function test_gitleaks_configuration_keeps_project_rules_and_does_not_hide_real_findings(): void
    {
        $config = (string) file_get_contents($this->root.'/.gitleaks.toml');

        foreach (['mercadopago-credential', 'laravel-app-key', 'dotenv-sensitive-value', 'database-dump-file', 'certificate-or-private-key-file'] as $rule) {
            $this->assertStringContainsString("id = \"{$rule}\"", $config);
        }
        $this->assertStringContainsString('useDefault = true', $config);
        // Em tests/ só a regra genérica é dispensada; as do projeto continuam valendo.
        $this->assertMatchesRegularExpression('~targetRules = \["generic-api-key"\]~', $config);

        $ignored = (string) file_get_contents($this->root.'/.gitleaksignore');
        $this->assertStringNotContainsString('backup/db_backup.sql', $ignored);
        $this->assertStringNotContainsString(':.env.example:', $ignored);
    }

    private function tempRepo(): string
    {
        $dir = sys_get_temp_dir().'/secret-guard-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $this->tempDirs[] = $dir;
        $this->shell(['git', 'init', '-q'], $dir);
        $this->shell(['git', 'config', 'user.email', 'teste@example.com'], $dir);
        $this->shell(['git', 'config', 'user.name', 'Teste'], $dir);
        $this->shell(['git', 'config', 'commit.gpgsign', 'false'], $dir);

        return $dir;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @return array{code: int, output: string}
     */
    private function shell(array $command, string $cwd, array $env = []): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env === [] ? null : $env + ['HOME' => $cwd]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'output' => $output];
    }

    private function fakeValue(): string
    {
        return 'valor-'.bin2hex(random_bytes(8));
    }
}
