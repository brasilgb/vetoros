<?php

namespace App\Console\Commands;

use App\Models\App\Image;
use App\Models\App\Order;
use App\Support\OrderFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copia fotos e assinaturas da pasta pública antiga (public/storage/orders/{número da OS}) para o
 * armazenamento privado por empresa e OS (VETOROS-SECURITY-FIX-01).
 *
 * Regras de segurança:
 * - simulação por padrão; só copia com --execute;
 * - nunca apaga nem altera os arquivos antigos, e nunca sobrescreve arquivo já migrado;
 * - confere o hash de cada cópia;
 * - arquivo ambíguo (pasta compartilhada entre empresas) não é atribuído: vai para o relatório.
 */
class MigrateOrderFiles extends Command
{
    protected $signature = 'vetoros:migrate-order-files
        {--execute : Copia de fato (sem esta opção, só simula)}';

    protected $description = 'Copia fotos e assinaturas de OS da pasta pública antiga para o armazenamento privado por empresa.';

    private const SIGNATURE = 'signature.png';

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $report = ['copied' => [], 'already_migrated' => [], 'ambiguous' => [], 'orphan' => [], 'missing' => [], 'failed' => []];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $root = rtrim((string) config('order_files.legacy_root'), '/');

        $this->info(($execute ? 'Migrando' : 'Simulação (use --execute para copiar)').': '.$root);

        if (! is_dir($root)) {
            $this->warn('Pasta antiga não encontrada; nada a migrar.');

            return self::SUCCESS;
        }

        $this->migrateImages($execute);
        $this->migrateSignatures($root, $execute);

        $this->table(['Situação', 'Quantidade'], collect($this->report)->map(fn ($items, $key) => [$this->label($key), count($items)])->values()->all());

        foreach ($this->report['ambiguous'] as $item) {
            $this->warn('Arquivo ambíguo, não atribuído: '.$item['legacy'].' — '.$item['reason']);
        }

        $reportPath = 'order-files-migration-'.now()->format('Ymd-His').($execute ? '' : '-simulacao').'.json';
        Storage::disk('local')->put($reportPath, json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info('Relatório: '.Storage::disk('local')->path($reportPath));

        return $this->report['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function migrateImages(bool $execute): void
    {
        Image::withoutGlobalScopes()->orderBy('id')->chunkById(500, function ($images) use ($execute): void {
            foreach ($images as $image) {
                $order = Order::withoutGlobalScopes()->find($image->order_id);
                if (! $order) {
                    $this->report['orphan'][] = ['image_id' => $image->id, 'reason' => 'OS inexistente'];

                    continue;
                }

                $target = OrderFiles::imagePath($order, $image->filename);
                if (OrderFiles::disk()->exists($target)) {
                    $this->report['already_migrated'][] = ['image_id' => $image->id, 'target' => $target];

                    continue;
                }

                if (OrderFiles::legacyFilenameIsShared($image)) {
                    $this->report['ambiguous'][] = [
                        'image_id' => $image->id,
                        'legacy' => $image->filename,
                        'reason' => 'mesmo nome de arquivo em fotos de outras OS (possível sobrescrita na pasta antiga)',
                    ];

                    continue;
                }

                $legacy = OrderFiles::legacyImagePath($image, $order);
                if (! $legacy) {
                    $this->report['missing'][] = ['image_id' => $image->id, 'filename' => $image->filename];

                    continue;
                }

                $this->copy($legacy, $target, ['image_id' => $image->id, 'tenant_id' => $order->tenant_id, 'order_id' => $order->id], $execute);
            }
        });
    }

    private function migrateSignatures(string $root, bool $execute): void
    {
        foreach (glob($root.'/*/'.self::SIGNATURE) ?: [] as $legacy) {
            $folder = basename(dirname($legacy));
            if (! ctype_digit($folder)) {
                continue;
            }

            // A pasta é o número da OS, repetido em cada empresa. Só é atribuível quando uma
            // única OS com esse número tem assinatura colhida.
            $candidates = Order::withoutGlobalScopes()
                ->where('order_number', (int) $folder)
                ->whereNotNull('customer_signature_captured_at')
                ->get(['id', 'tenant_id', 'order_number']);

            if ($candidates->count() !== 1) {
                $this->report[$candidates->isEmpty() ? 'orphan' : 'ambiguous'][] = [
                    'legacy' => $legacy,
                    'reason' => $candidates->isEmpty()
                        ? 'nenhuma OS com este número tem assinatura registrada'
                        : 'assinatura colhida em mais de uma empresa para a OS '.$folder.' (OS '.$candidates->pluck('id')->implode(', ').')',
                    'orders' => $candidates->map(fn (Order $order) => ['order_id' => $order->id, 'tenant_id' => $order->tenant_id])->all(),
                ];

                continue;
            }

            $order = $candidates->first();
            $target = OrderFiles::signaturePath($order);
            if (OrderFiles::disk()->exists($target)) {
                $this->report['already_migrated'][] = ['order_id' => $order->id, 'target' => $target];

                continue;
            }

            $this->copy($legacy, $target, ['tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'signature' => true], $execute);
        }
    }

    private function copy(string $legacy, string $target, array $context, bool $execute): void
    {
        $entry = [...$context, 'legacy' => $legacy, 'target' => $target, 'sha256' => hash_file('sha256', $legacy)];

        if (! $execute) {
            $this->report['copied'][] = [...$entry, 'simulated' => true];

            return;
        }

        $contents = (string) file_get_contents($legacy);
        OrderFiles::disk()->put($target, $contents);

        if (hash('sha256', OrderFiles::disk()->get($target)) !== $entry['sha256']) {
            OrderFiles::disk()->delete($target);
            $this->report['failed'][] = [...$entry, 'reason' => 'hash da cópia diferente do original'];

            return;
        }

        $this->report['copied'][] = $entry;
    }

    private function label(string $key): string
    {
        return [
            'copied' => $this->option('execute') ? 'Copiados' : 'A copiar',
            'already_migrated' => 'Já migrados',
            'ambiguous' => 'Ambíguos (revisão manual)',
            'orphan' => 'Sem dono identificável',
            'missing' => 'Arquivo antigo ausente',
            'failed' => 'Falhas',
        ][$key];
    }
}
