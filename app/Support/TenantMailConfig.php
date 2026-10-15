<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantMailConfig
{
    public static function applySystemDefault(): void
    {
        $system = config('mail.system', []);

        Config::set('mail.default', $system['mailer'] ?? 'smtp');
        Config::set('mail.mailers.smtp.transport', 'smtp');
        Config::set('mail.mailers.smtp.host', $system['host'] ?? null);
        Config::set('mail.mailers.smtp.port', (int) ($system['port'] ?? 587));
        Config::set('mail.mailers.smtp.encryption', $system['encryption'] ?? 'tls');
        Config::set('mail.mailers.smtp.scheme', $system['scheme'] ?? ((int) ($system['port'] ?? 587) === 465 ? 'smtps' : 'smtp'));
        Config::set('mail.mailers.smtp.url', $system['url'] ?? null);
        Config::set('mail.mailers.smtp.username', $system['username'] ?? null);
        Config::set('mail.mailers.smtp.password', $system['password'] ?? null);
        Config::set('mail.from.address', $system['from_address'] ?? config('mail.from.address'));
        Config::set('mail.from.name', $system['from_name'] ?? config('app.name'));
        app('mail.manager')->purge();
    }

    public static function hasConfiguredForTenantId(?int $tenantId): bool
    {
        if (! $tenantId || ! Schema::hasTable('others')) {
            return self::hasSystemDefaultConfigured();
        }

        $mailConfig = DB::table('others')
            ->where('tenant_id', $tenantId)
            ->select([
                'mail_host',
                'mail_port',
                'mail_username',
                'mail_password',
            ])
            ->orderByDesc('id')
            ->first();

        if (! $mailConfig) {
            return false;
        }

        return ! empty($mailConfig->mail_host)
            && ! empty($mailConfig->mail_port)
            && ! empty($mailConfig->mail_username)
            && ! empty($mailConfig->mail_password);
    }

    public static function applyForTenantId(?int $tenantId): void
    {
        if (! $tenantId || ! Schema::hasTable('others')) {
            self::applySystemDefault();

            return;
        }

        $mailConfig = DB::table('others')
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
            ->first();

        if (! $mailConfig || empty($mailConfig->mail_host) || empty($mailConfig->mail_username)) {
            Config::set('mail.default', 'log');
            app('mail.manager')->purge();

            return;
        }

        $password = null;
        if (! empty($mailConfig->mail_password)) {
            try {
                $password = Crypt::decryptString($mailConfig->mail_password);
            } catch (DecryptException) {
                $password = null;
            }
        }

        Config::set('mail.default', $mailConfig->mail_mailer ?: 'smtp');
        Config::set('mail.mailers.smtp.transport', 'smtp');
        Config::set('mail.mailers.smtp.host', $mailConfig->mail_host);
        Config::set('mail.mailers.smtp.port', (int) ($mailConfig->mail_port ?: 587));
        Config::set('mail.mailers.smtp.encryption', $mailConfig->mail_encryption ?: 'tls');
        // O tenant não herda smtps/MAIL_URL do SMTP corporativo.
        Config::set('mail.mailers.smtp.scheme', ($mailConfig->mail_encryption === 'ssl' || (int) $mailConfig->mail_port === 465) ? 'smtps' : 'smtp');
        Config::set('mail.mailers.smtp.url', null);
        Config::set('mail.mailers.smtp.username', $mailConfig->mail_username);
        Config::set('mail.mailers.smtp.password', $password);
        Config::set('mail.from.address', $mailConfig->mail_from_address ?: config('mail.from.address'));
        Config::set('mail.from.name', $mailConfig->mail_from_name ?: config('app.name'));
        app('mail.manager')->purge();
    }

    private static function hasSystemDefaultConfigured(): bool
    {
        return ! empty(config('mail.system.host')) && ! empty(config('mail.system.port'));
    }
}
