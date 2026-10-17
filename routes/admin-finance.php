<?php

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
