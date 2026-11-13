<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria somente leitura: a APP_KEY deste ambiente decifra os dados cifrados do banco?
 *
 * Não grava nada, não recifra e nunca exibe valores (cifrados ou decifrados): só contagens
 * e, para ilegíveis, os IDs de tenant afetados. Uso antes de deploy ou migração de banco.
 */
class AuditAppKey extends Command
{
    protected $signature = 'security:audit-app-key';

    protected $description = 'Verifica, sem alterar nada, se a APP_KEY atual decifra as credenciais SMTP e da Spedy';

    public function handle(): int
    {
        $rows = [];
        $unreadableTotal = 0;

        // A chave pública das OS (orders.public_access_key) deixou de ser auditada: a consulta
        // pública foi removida na VETOROS-PERFORMANCE-OS-04 e o valor não é mais lido.
        if (Schema::hasTable('others')) {
            [$row, $unreadable] = $this->audit('others.mail_password (SMTP)', 'others', 'mail_password', fn ($value) => Crypt::decryptString($value));
            $rows[] = $row;
            $unreadableTotal += $unreadable;
        }

        if (Schema::hasTable('spedy_platform_settings')) {
            foreach (['owner_api_key', 'webhook_secret'] as $column) {
                [$row, $unreadable] = $this->audit("spedy_platform_settings.{$column}", 'spedy_platform_settings', $column, fn ($value) => decrypt($value, false), false);
                $rows[] = $row;
                $unreadableTotal += $unreadable;
            }
        }

        $this->table(['Campo', 'Preenchidos', 'Legíveis', 'Ilegíveis', 'Tenants com ilegíveis'], $rows);

        if ($unreadableTotal > 0) {
            $this->error("{$unreadableTotal} valor(es) cifrado(s) não decifram com a APP_KEY atual. Não altere a chave nem recifre sem um plano aprovado.");

            return self::FAILURE;
        }

        $this->info('A APP_KEY atual decifra todos os valores auditados.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<int, string|int>, 1: int}
     */
    private function audit(string $label, string $table, string $column, callable $decrypt, bool $hasTenant = true): array
    {
        $filled = 0;
        $readable = 0;
        $tenants = [];

        $query = DB::table($table)->whereNotNull($column)->where($column, '!=', '')->orderBy('id');
        $select = $hasTenant ? ['id', 'tenant_id', $column] : ['id', $column];

        $query->select($select)->chunkById(500, function ($chunk) use ($column, $decrypt, $hasTenant, &$filled, &$readable, &$tenants): void {
            foreach ($chunk as $record) {
                $filled++;

                try {
                    $decrypt($record->{$column});
                    $readable++;
                } catch (DecryptException) {
                    if ($hasTenant) {
                        $tenants[(int) $record->tenant_id] = true;
                    }
                }
            }
        });

        $unreadable = $filled - $readable;
        $tenantList = $tenants === [] ? '—' : implode(', ', array_keys($tenants));

        return [[$label, $filled, $readable, $unreadable, $hasTenant ? $tenantList : 'n/a'], $unreadable];
    }
}
