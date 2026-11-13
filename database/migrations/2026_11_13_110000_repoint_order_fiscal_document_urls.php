<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VETOROS-PERFORMANCE-OS-04: a consulta pública de OS foi removida, inclusive o PDF da NFS-e em
 * /os/{token}/fiscal-proof/pdf. As OS com nota emitida pelo sistema guardavam esse link em
 * orders.fiscal_document_url; ele passa a apontar para a rota autenticada da nota
 * (app.fiscal-documents.file), a mesma usada pelas vendas. Só reescreve a URL: nenhuma OS, nota
 * ou dado fiscal é apagado. Links informados manualmente (outro formato) ficam como estão.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->select(['id', 'tenant_id'])
            ->where('fiscal_document_url', 'like', '%/os/%/fiscal-proof/pdf')
            ->orderBy('id')
            ->chunkById(500, function ($orders): void {
                foreach ($orders as $order) {
                    $documentId = DB::table('fiscal_documents')
                        ->where('tenant_id', $order->tenant_id)
                        ->where('documentable_type', 'App\\Models\\App\\Order')
                        ->where('documentable_id', $order->id)
                        ->where('status', 'authorized')
                        ->orderByDesc('id')
                        ->value('id');

                    // Sem nota autorizada localizável, o valor antigo fica (nada é apagado).
                    if ($documentId) {
                        DB::table('orders')->where('id', $order->id)->update([
                            'fiscal_document_url' => route('app.fiscal-documents.file', ['fiscalDocument' => $documentId, 'format' => 'pdf']),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Sem volta: a rota pública antiga não existe mais.
    }
};
