<?php

use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Controllers\Admin\FiscalDocumentController;
use App\Http\Controllers\Admin\HelpTopicController;
use App\Http\Controllers\Admin\PeriodController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SupportSessionController;
use App\Http\Controllers\Admin\TenantCommercialController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantFeedbackController;
use App\Http\Controllers\Admin\TenantImprovementRequestController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/tenants/{tenant}/subscription-email-preview/{scenario}', [TenantController::class, 'previewSubscriptionEmail'])
    ->name('tenants.subscription-email-preview');
Route::get('/tenants/{tenant}/subscription-invoice-email-preview', [TenantController::class, 'previewSubscriptionInvoiceEmail'])
    ->name('tenants.subscription-invoice-email-preview');
Route::post('/tenants/{tenant}/subscription-email-send/{scenario}', [TenantController::class, 'sendSubscriptionEmail'])
    ->name('tenants.subscription-email-send');
// Condições comerciais e faturamento do SaaS (VETOR-SAAS-CONTRATO-01/02).
Route::put('/tenants/{tenant}/commercial', [TenantCommercialController::class, 'update'])->name('tenants.commercial.update');
Route::post('/tenants/{tenant}/commercial/revert', [TenantCommercialController::class, 'revert'])->name('tenants.commercial.revert');
Route::delete('/tenants/{tenant}/commercial/revert', [TenantCommercialController::class, 'cancelRevert'])->name('tenants.commercial.revert.cancel');
Route::post('/tenants/{tenant}/charges/{charge}/pay', [TenantCommercialController::class, 'pay'])->name('tenants.charges.pay');
Route::post('/tenants/{tenant}/charges/{charge}/refund', [TenantCommercialController::class, 'refund'])->name('tenants.charges.refund');
Route::post('/tenants/{tenant}/charges/{charge}/adjust', [TenantCommercialController::class, 'adjust'])->name('tenants.charges.adjust');
Route::post('/tenants/{tenant}/charges/{charge}/emit', [TenantCommercialController::class, 'emit'])->name('tenants.charges.emit');
Route::post('/tenants/{tenant}/charges/{charge}/resend-invoice', [TenantCommercialController::class, 'resendInvoice'])->middleware('throttle:10,1')->name('tenants.charges.resend-invoice');
Route::post('/tenants/{tenant}/charges/{charge}/resend-fiscal', [TenantCommercialController::class, 'resendFiscal'])->middleware('throttle:10,1')->name('tenants.charges.resend-fiscal');
// Modo Suporte (VETOR-STABILIZE-03): entrar no painel da empresa com a própria identidade.
Route::post('/tenants/{tenant}/support', [SupportSessionController::class, 'store'])->middleware('throttle:5,1')->name('tenants.support.start');
Route::get('/tenant-feedbacks', [TenantFeedbackController::class, 'index'])->name('tenant-feedbacks.index');
Route::patch('/tenant-feedbacks/{tenantFeedback}', [TenantFeedbackController::class, 'update'])->name('tenant-feedbacks.update');
Route::get('/tenant-improvement-requests', [TenantImprovementRequestController::class, 'index'])->name('tenant-improvement-requests.index');
Route::get('/tenant-improvement-requests/{tenantImprovementRequest}/preview/admin-email', [TenantImprovementRequestController::class, 'previewAdminEmail'])
    ->name('tenant-improvement-requests.preview-admin-email');
Route::get('/tenant-improvement-requests/{tenantImprovementRequest}/preview/customer-email', [TenantImprovementRequestController::class, 'previewCustomerEmail'])
    ->name('tenant-improvement-requests.preview-customer-email');
Route::patch('/tenant-improvement-requests/{tenantImprovementRequest}', [TenantImprovementRequestController::class, 'update'])->name('tenant-improvement-requests.update');
Route::get('/fiscal-documents', [FiscalDocumentController::class, 'index'])->name('fiscal-documents.index');
Route::get('/help-topics', [HelpTopicController::class, 'index'])->name('help-topics.index');
Route::post('/help-topics/import', [HelpTopicController::class, 'import'])->name('help-topics.import');
Route::resource('/tenants', TenantController::class);
Route::resource('/branches', BranchController::class);
Route::resource('/plans', PlanController::class);
Route::resource('/features', FeatureController::class);
Route::resource('/periods', PeriodController::class);
Route::resource('/settings', SettingController::class);
Route::resource('/users', UserController::class);
