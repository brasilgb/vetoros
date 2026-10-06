<?php

use App\Http\Controllers\Admin\Fiscal\FiscalCompanyController;
use App\Http\Controllers\Admin\Fiscal\FiscalIntegrationController;
use App\Http\Controllers\Admin\Fiscal\FiscalMonitoringController;
use App\Http\Controllers\Admin\Fiscal\SaasInvoiceController;
use Illuminate\Support\Facades\Route;

/*
| RootAdmin → Fiscal. Grupo com middleware `root.admin` (bootstrap/app.php):
| somente usuário sem tenant e com papel root; demais recebem 403.
*/

Route::get('/', [FiscalIntegrationController::class, 'show'])->name('integration');
Route::put('/integration', [FiscalIntegrationController::class, 'update'])->name('integration.update');
Route::post('/integration/diagnose', [FiscalIntegrationController::class, 'diagnose'])->middleware('throttle:10,1')->name('integration.diagnose');
Route::post('/integration/webhook', [FiscalIntegrationController::class, 'configureWebhook'])->middleware('throttle:5,1')->name('integration.webhook');

Route::get('/companies', [FiscalCompanyController::class, 'index'])->name('companies.index');
Route::put('/companies/{tenant}', [FiscalCompanyController::class, 'update'])->name('companies.update');
Route::post('/companies/{tenant}/register', [FiscalCompanyController::class, 'register'])->name('companies.register');
Route::post('/companies/{tenant}/check', [FiscalCompanyController::class, 'check'])->middleware('throttle:20,1')->name('companies.check');

Route::get('/monitoring', [FiscalMonitoringController::class, 'index'])->name('monitoring');

Route::get('/saas', [SaasInvoiceController::class, 'index'])->name('saas.index');
Route::put('/saas/issuer', [SaasInvoiceController::class, 'updateIssuer'])->name('saas.issuer.update');
Route::post('/saas/issuer/register', [SaasInvoiceController::class, 'registerIssuer'])->name('saas.issuer.register');
Route::post('/saas/issuer/certificate', [SaasInvoiceController::class, 'issuerCertificate'])->name('saas.issuer.certificate');
Route::post('/saas/payments/{payment}/emit', [SaasInvoiceController::class, 'emit'])->name('saas.emit');
Route::post('/saas/documents/{document}/refresh', [SaasInvoiceController::class, 'refresh'])->name('saas.refresh');
Route::post('/saas/documents/{document}/cancel', [SaasInvoiceController::class, 'cancel'])->name('saas.cancel');
Route::get('/saas/documents/{document}/file/{format}', [SaasInvoiceController::class, 'file'])->name('saas.file');
Route::post('/saas/documents/{document}/send', [SaasInvoiceController::class, 'send'])->middleware('throttle:10,1')->name('saas.send');
