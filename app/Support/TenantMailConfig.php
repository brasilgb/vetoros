<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantMailConfig
{
    /** Build a fresh institutional transport without changing the default mailer. */
    public static function systemMailer(): Mailer
    {
        $system = config('mail.system', []);
        $driver = $system['mailer'] ?? 'smtp';
        $config = config('mail.mailers.'.$driver, []);
        if ($driver === 'smtp') {
            $config = array_merge($config, [
                'transport' => 'smtp',
                'host' => $system['host'] ?? null,
                'port' => (int) ($system['port'] ?? 587),
                'scheme' => $system['scheme'] ?? ((int) ($system['port'] ?? 587) === 465 ? 'smtps' : 'smtp'),
                'url' => $system['url'] ?? null,
                'username' => $system['username'] ?? null,
                'password' => $system['password'] ?? null,
            ]);
        }

        $mailer = app('mail.manager')->build($config);
        $mailer->alwaysFrom($system['from_address'] ?? config('mail.from.address'), $system['from_name'] ?? config('mail.from.name'));

        return $mailer;
    }

    public static function hasConfiguredForTenantId(?int $tenantId): bool
    {
        if (! $tenantId || ! self::othersTableExists()) {
            return false;
        }

        $mailConfig = self::tenantRow($tenantId);

        return $mailConfig
            && ! empty($mailConfig->mail_host)
            && ! empty($mailConfig->mail_port)
            && ! empty($mailConfig->mail_username)
            && ! empty($mailConfig->mail_password);
    }

    /** Tenant credentials never enter global config or the MailManager cache. */
    public static function tenantMailer(?int $tenantId): Mailer
    {
        if (! self::hasConfiguredForTenantId($tenantId)) {
            throw new \RuntimeException('SMTP do tenant não configurado.');
        }

        $mailConfig = self::tenantRow($tenantId);
        try {
            $password = Crypt::decryptString($mailConfig->mail_password);
        } catch (DecryptException) {
            throw new \RuntimeException('Credenciais SMTP do tenant indisponíveis.');
        }
        if ($password === '') {
            throw new \RuntimeException('SMTP do tenant não configurado.');
        }

        // A missing sender may use the tenant's own SMTP login, never MAIL_FROM_*.
        $from = $mailConfig->mail_from_address ?: $mailConfig->mail_username;
        if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Remetente SMTP do tenant não configurado.');
        }

        $mailer = app('mail.manager')->build([
            'transport' => 'smtp',
            'host' => $mailConfig->mail_host,
            'port' => (int) $mailConfig->mail_port,
            'scheme' => ($mailConfig->mail_encryption === 'ssl' || (int) $mailConfig->mail_port === 465) ? 'smtps' : 'smtp',
            'username' => $mailConfig->mail_username,
            'password' => $password,
            'timeout' => config('mail.mailers.smtp.timeout'),
            'local_domain' => config('mail.mailers.smtp.local_domain'),
        ]);
        $mailer->alwaysFrom($from, $mailConfig->mail_from_name ?: config('app.name'));

        return $mailer;
    }

    /** Esquece a configuração lida nesta requisição (chamado quando `others` é gravado). */
    public static function forget(): void
    {
        RequestCache::forget('tenant-mail:');
    }

    /**
     * Uma leitura por tenant e requisição: listas como a de Ordens consultavam a mesma
     * linha (e o information_schema) duas vezes por registro exibido.
     */
    private static function tenantRow(int $tenantId): ?object
    {
        return RequestCache::remember('tenant-mail:'.$tenantId, fn () => DB::table('others')
            ->where('tenant_id', $tenantId)
            ->select([
                'mail_mailer',
                'mail_host',
                'mail_port',
                'mail_username',
                'mail_password',
                'mail_encryption',
                'mail_from_address',
                'mail_from_name',
            ])
            ->orderByDesc('id')
            ->first());
    }

    private static function othersTableExists(): bool
    {
        return RequestCache::remember('tenant-mail:schema', fn () => Schema::hasTable('others'));
    }
}
