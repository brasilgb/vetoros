<?php

use App\Http\Controllers\App\BudgetController;
use App\Http\Controllers\App\CompanyController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\ImageController;
use App\Http\Controllers\App\OrderController;
use App\Http\Controllers\App\PartController;
use App\Http\Controllers\App\ReportController;
use App\Http\Controllers\App\ServiceController;
use App\Http\Controllers\App\TechnicianPushTokenController;
use App\Http\Controllers\App\TechnicianScheduleController;
use App\Http\Controllers\App\UserController;
use App\Http\Controllers\App\WebhookController;
use App\Http\Controllers\Integration\RegistrationCheckController;
use App\Http\Middleware\VerifyCrmIntegrationToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
// routes/api.php (Lembre-se de excluir essa rota da verificação CSRF no VerifyCsrfToken.php)
Route::post('/webhooks/mercadopago/{token}', [WebhookController::class, 'handle'])->name('webhook.mercadopago');
Route::post('/integrations/registration-check', RegistrationCheckController::class)
    ->middleware(['throttle:60,1', VerifyCrmIntegrationToken::class])
    ->name('api.integrations.registration-check');
Route::post('/loginuser', [UserController::class, 'loginuser'])->name('loginuser');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/ordercli/{customer}', [OrderController::class, 'getOrderCli'])->name('ordercli');
    Route::get('/allorder', [OrderController::class, 'allOrder'])->name('allorder');
    Route::get('/order/{order}', [OrderController::class, 'getOrder'])->name('order');
    Route::post('/order/{order}/assinatura', [OrderController::class, 'storeSignature'])->name('api.orders.signature');
    Route::get('/clientes', [CustomerController::class, 'getClientes']);
    Route::post('/clientes/pre-cadastro', [CustomerController::class, 'preRegister'])->name('api.customers.pre-register');
    Route::get('/relatorios/equipamentos/filtros', [ReportController::class, 'equipmentFilters'])->name('api.reports.equipment-filters');
    Route::get('/relatorios/equipamentos', [ReportController::class, 'equipmentReport'])->name('api.reports.equipment');
    Route::get('/orcamentos/filtros', [BudgetController::class, 'budgetFilters'])->name('api.budgets.filters');
    Route::get('/orcamentos/modelos', [BudgetController::class, 'budgetModels'])->name('api.budgets.models');
    Route::get('/orcamentos/servicos', [BudgetController::class, 'budgetServices'])->name('api.budgets.services');
    Route::get('/orcamentos', [BudgetController::class, 'getOrcamentos'])->name('api.budgets.show');
    Route::post('/orcamentos', [BudgetController::class, 'getOrcamentos']);
    Route::post('/servicos', [ServiceController::class, 'getServicos']);
    Route::post('/tecnico/push-token', [TechnicianPushTokenController::class, 'store'])->name('api.technician.push-token.store');
    Route::delete('/tecnico/push-token', [TechnicianPushTokenController::class, 'destroy'])->name('api.technician.push-token.destroy');
    Route::get('/tecnico/dashboard', [TechnicianScheduleController::class, 'dashboard'])->name('api.technician.dashboard');
    Route::get('/tecnico/materiais', [TechnicianScheduleController::class, 'materials'])->name('api.technician.materials.index');
    Route::get('/tecnico/agendamentos', [TechnicianScheduleController::class, 'index'])->name('api.technician.schedules.index');
    Route::get('/tecnico/agendamentos/{schedule}', [TechnicianScheduleController::class, 'show'])->name('api.technician.schedules.show');
    Route::post('/tecnico/agendamentos/{schedule}/status', [TechnicianScheduleController::class, 'updateStatus'])->name('api.technician.schedules.status');
    Route::post('/tecnico/agendamentos/{schedule}/check-in', [TechnicianScheduleController::class, 'checkIn'])->name('api.technician.schedules.check-in');
    Route::post('/tecnico/agendamentos/{schedule}/check-out', [TechnicianScheduleController::class, 'checkOut'])->name('api.technician.schedules.check-out');
    Route::post('/tecnico/agendamentos/{schedule}/relatorio', [TechnicianScheduleController::class, 'updateReport'])->name('api.technician.schedules.report');
    Route::post('/tecnico/agendamentos/{schedule}/checklist', [TechnicianScheduleController::class, 'updateChecklist'])->name('api.technician.schedules.checklist');
    Route::post('/tecnico/agendamentos/{schedule}/pagamento', [TechnicianScheduleController::class, 'recordPayment'])->name('api.technician.schedules.payment');
    Route::post('/tecnico/agendamentos/{schedule}/solicitar-fechamento', [TechnicianScheduleController::class, 'requestServiceClosure'])->name('api.technician.schedules.request-closure');
    Route::get('/tecnico/agendamentos/{schedule}/imagens', [TechnicianScheduleController::class, 'images'])->name('api.technician.schedules.images.index');
    Route::post('/tecnico/agendamentos/{schedule}/imagens', [TechnicianScheduleController::class, 'uploadImage'])->name('api.technician.schedules.images.store');
    Route::delete('/tecnico/agendamentos/{schedule}/imagens/{image}', [TechnicianScheduleController::class, 'deleteImage'])->name('api.technician.schedules.images.destroy');
    Route::get('/empresa', [CompanyController::class, 'getEmpresaInfo']);
    Route::delete('/deleteimage/{aimage}', [ImageController::class, 'deleteImageOrder'])->name('deleteimage');
    Route::get('/images/{order}', [ImageController::class, 'getImages'])->name('images');
    Route::post('/upload', [ImageController::class, 'upload'])->name('upload');
    Route::get('/logoutuser', [UserController::class, 'logoutuser'])->name('logoutuser');
    Route::get('/getparts/{reference_number}', [PartController::class, 'getPartsForPartNumber'])->name('getparts');
});
