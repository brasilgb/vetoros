<?php

use App\Http\Controllers\Admin\Finance\EfiIntegrationTestController;
use App\Http\Controllers\Admin\Finance\EfiSettingsController;
use Illuminate\Support\Facades\Route;

/*
| RootAdmin → Financeiro → Efí Bank (VETOR-SAAS-EFI-02). Grupo com middleware `root.admin`
| (bootstrap/app.php): somente usuário sem tenant e com papel root.
*/

Route::get('/efi', [EfiSettingsController::class, 'show'])->name('efi');
Route::put('/efi', [EfiSettingsController::class, 'update'])->name('efi.update');
Route::post('/efi/certificate', [EfiSettingsController::class, 'certificate'])->name('efi.certificate');
Route::post('/efi/test', [EfiSettingsController::class, 'test'])->middleware('throttle:10,1')->name('efi.test');
Route::post('/efi/webhook', [EfiSettingsController::class, 'webhook'])->middleware('throttle:5,1')->name('efi.webhook');
Route::post('/efi/homologation', [EfiSettingsController::class, 'homologation'])->name('efi.homologation');
Route::post('/efi/payments/{payment}/reconcile', [EfiSettingsController::class, 'reconcile'])->middleware('throttle:20,1')->name('efi.payments.reconcile');
Route::post('/efi/payments/{payment}/refund', [EfiSettingsController::class, 'refund'])->middleware('throttle:5,1')->name('efi.payments.refund');

// Modal "Testar pagamento" (VETOROS-EFI-TEST-01): cobranças isoladas, source=integration_test.
Route::post('/efi/integration-tests', [EfiIntegrationTestController::class, 'store'])->middleware('throttle:10,1')->name('efi.integration-tests.store');
Route::get('/efi/integration-tests/{test}', [EfiIntegrationTestController::class, 'show'])->name('efi.integration-tests.show');
Route::post('/efi/integration-tests/{test}/refresh', [EfiIntegrationTestController::class, 'refresh'])->middleware('throttle:30,1')->name('efi.integration-tests.refresh');
