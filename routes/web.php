<?php

use App\Http\Controllers\Admin\SupportSessionController;
use App\Http\Controllers\App\SaasCheckoutController;
use App\Http\Controllers\App\SubscriptionController;
use App\Http\Controllers\OrderFileController;
use App\Http\Controllers\OsController;
use App\Http\Controllers\SharedFiscalDocumentController;
use App\Http\Controllers\SharedSaasFiscalDocumentController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PlanController;
use App\Http\Controllers\Site\WebChatProxyController;
use App\Http\Controllers\TenantFeedbackController;
use App\Support\SiteMeta;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::prefix('chat')->middleware('throttle:30,1')->name('chat.')->group(function (): void {
    Route::post('conversations', [WebChatProxyController::class, 'start'])->name('start');
    Route::get('conversations/{conversation}', [WebChatProxyController::class, 'show'])->whereUuid('conversation')->name('show');
    Route::post('conversations/{conversation}/messages', [WebChatProxyController::class, 'send'])->whereUuid('conversation')->name('messages.store');
    Route::patch('conversations/{conversation}/contact', [WebChatProxyController::class, 'identify'])->whereUuid('conversation')->name('contact.update');
    Route::post('conversations/{conversation}/close', [WebChatProxyController::class, 'close'])->whereUuid('conversation')->name('close');
});
Route::get('/planos', [PlanController::class, 'index'])->name('plans.index');
Route::get('/os/{token}', [OsController::class, 'index'])->name('os.token');
Route::post('/os/{token}/access', [OsController::class, 'authorizeAccess'])
    ->middleware('throttle:6,1')
    ->name('os.access');
Route::post('/os/{token}/budget-status', [OsController::class, 'updateBudgetStatus'])
    ->name('orders.budget.status');
Route::post('/os/{token}/acknowledge-notification', [OsController::class, 'acknowledgeNotification'])
    ->name('orders.notification.acknowledge');
Route::post('/os/{token}/acknowledge-pickup', [OsController::class, 'acknowledgePickup'])
    ->name('orders.pickup.acknowledge');
Route::get('/os/{token}/receipt/{type}', [OsController::class, 'receipt'])
    ->name('os.receipt');
Route::get('/os/{token}/payment-proof', [OsController::class, 'paymentProof'])
    ->name('os.payment-proof');
Route::get('/os/{token}/fiscal-proof', [OsController::class, 'fiscalProof'])
    ->name('os.fiscal-proof');
Route::get('/os/{token}/fiscal-proof/pdf', [OsController::class, 'fiscalProofFile'])
    ->middleware('throttle:30,1')
    ->name('os.fiscal-proof.pdf');
// PDF/XML de NFS-e de contrato para o cliente final: link assinado e temporário (VETOR-FISCAL-05.3).
Route::get('/nfse/{document}/{format}', SharedFiscalDocumentController::class)
    ->whereNumber('document')
    ->where('format', 'pdf|xml')
    ->middleware('throttle:30,1')
    ->name('fiscal-documents.shared');
// PDF/XML da NFS-e do SaaS para o cliente contratante: link assinado e temporário (VETOR-SAAS-CONTRATO-01).
Route::get('/nfse-saas/{document}/{format}', SharedSaasFiscalDocumentController::class)
    ->whereNumber('document')
    ->where('format', 'pdf|xml')
    ->middleware('throttle:30,1')
    ->name('saas-fiscal-documents.shared');
// Fotos e assinatura da OS (VETOROS-SECURITY-FIX-01): só por URL assinada e temporária, emitida depois
// da autorização (tela da OS, API do app ou portal público liberado).
Route::get('/files/orders/images/{image}', [OrderFileController::class, 'image'])
    ->whereNumber('image')
    ->middleware(['signed', 'throttle:300,1'])
    ->name('order-files.image');
Route::get('/files/orders/{order}/signature', [OrderFileController::class, 'signature'])
    ->whereNumber('order')
    ->middleware(['signed', 'throttle:300,1'])
    ->name('order-files.signature');
Route::post('/os/{token}/feedback', [OsController::class, 'submitFeedback'])
    ->name('os.feedback.submit');
Route::get('/experience/{token}', [TenantFeedbackController::class, 'show'])->name('tenant.feedback.show');
Route::post('/experience/{token}', [TenantFeedbackController::class, 'submit'])->name('tenant.feedback.submit');

Route::get('/privacidade', function () {
    return Inertia::render('site/privacy/index')->withViewData(['meta' => SiteMeta::privacy()]);
});

Route::get('/termos', function () {
    return Inertia::render('site/terms/index')->withViewData(['meta' => SiteMeta::terms()]);
});
/*
|--------------------------------------------------------------------------
| Assinatura e Pagamentos
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Modo Suporte (VETOR-STABILIZE-03): "Encerrar suporte" fica fora do painel admin, que é
    // inacessível enquanto o suporte está ativo.
    Route::post('/support/end', [SupportSessionController::class, 'destroy'])->name('support.end');

    // Minha assinatura (VETOR-SAAS-EFI-04): consulta e pagamento em qualquer estado da assinatura.
    Route::get('/subscription', [SubscriptionController::class, 'show'])
        ->name('subscription.show');

    // Tela de bloqueio
    Route::get('/subscription/blocked', [SubscriptionController::class, 'blocked'])
        ->name('subscription.blocked');

    // Rota para checagem via Polling (AJAX)
    Route::get('/api/subscription/check', [SubscriptionController::class, 'checkStatus'])
        ->name('subscription.check_status');

    // Checkout Efí (VETOR-SAAS-EFI-02): Pix, boleto/Bolix, cartão e recorrência. Liberação só pela confirmação na API.
    Route::post('/subscription/efi/pix', [SaasCheckoutController::class, 'pix'])->middleware('throttle:20,1')->name('subscription.efi.pix');
    Route::post('/subscription/efi/billet', [SaasCheckoutController::class, 'billet'])->middleware('throttle:20,1')->name('subscription.efi.billet');
    Route::post('/subscription/efi/card', [SaasCheckoutController::class, 'card'])->middleware('throttle:10,1')->name('subscription.efi.card');
    Route::post('/subscription/efi/recurring/cancel', [SaasCheckoutController::class, 'cancelRecurring'])->middleware('throttle:5,1')->name('subscription.efi.recurring.cancel');
    Route::get('/subscription/efi/payments/{payment}', [SaasCheckoutController::class, 'status'])->name('subscription.efi.status');
    // Consultar de novo: o servidor pergunta à Efí (nunca o navegador decide a quitação).
    Route::post('/subscription/efi/payments/{payment}/refresh', [SaasCheckoutController::class, 'refresh'])->middleware('throttle:6,1')->name('subscription.efi.refresh');
});

require __DIR__.'/auth.php';
