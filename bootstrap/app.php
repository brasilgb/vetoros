<?php

use App\Http\Middleware\AdminAccessMiddleware;
use App\Http\Middleware\AppAccessMiddleware;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\Cors;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RootAdminOnly;
use App\Http\Middleware\StartSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

// O app já mapeia todos os eventos/listeners explicitamente em App\Providers\EventServiceProvider.
// Sem isto, o Laravel também auto-descobre os mesmos listeners em app/Listeners e registra cada um
// DUAS vezes (uma via o array $listen, outra via auto-discovery), disparando toda notificação,
// e-mail, webhook e log de auditoria duas vezes por evento.
EventServiceProvider::disableEventDiscovery();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            // Rotas da Landing Page (públicas)
            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::middleware('web')
                ->group(base_path('routes/settings.php'));

            Route::middleware(['web', 'auth', 'app', 'check.subscription'])
                ->prefix('app')
                ->name('app.')
                ->group(base_path('routes/app.php'));

            // Administração fiscal central (Spedy, emissores, monitoramento, notas do SaaS).
            Route::middleware(['web', 'auth', 'root.admin'])
                ->prefix('admin/fiscal')
                ->name('admin.fiscal.')
                ->group(base_path('routes/admin-fiscal.php'));

            Route::middleware(['web', 'auth', 'admin'])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->alias([
            'admin' => AdminAccessMiddleware::class,
            'app' => AppAccessMiddleware::class,
            'check.subscription' => CheckSubscriptionStatus::class,
            'root.admin' => RootAdminOnly::class,
        ]);
        $middleware->web(replace: [
            BaseStartSession::class => StartSession::class,
        ], append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            Cors::class,
        ]);
        // Adicionando exceção de CSRF para o Webhook do Mercado Pago
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/mercadopago/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (Response $response, Throwable $exception, $request) {
            $status = $response->getStatusCode();

            if ($request->expectsJson()) {
                return $response;
            }

            if ($response->getStatusCode() === 403 && ! $request->expectsJson()) {
                // Volta para a página anterior; nunca para a própria URL negada (loop).
                // Endpoints de dados não viram "URL anterior" (App\Http\Middleware\StartSession).
                $target = url()->previous();

                if (rtrim($target, '/') === rtrim($request->url(), '/') || rtrim($target, '/') === rtrim($request->fullUrl(), '/')) {
                    $target = Route::has('app.dashboard') && ! $request->routeIs('app.dashboard') ? route('app.dashboard') : url('/');
                }

                return redirect()
                    ->to($target)
                    ->with('authorization_error', 'Esta ação não é autorizada.');
            }

            if ($status === 419) {
                return redirect()
                    ->back()
                    ->withInput($request->except(['password', 'password_confirmation', 'current_password']))
                    ->with('error', 'Sua sessão expirou. Atualize a página e tente novamente.');
            }

            if (! $request->isMethod('GET') && $status === 404) {
                return redirect()
                    ->back()
                    ->withInput($request->except(['password', 'password_confirmation', 'current_password']))
                    ->with('error', 'Não foi possível encontrar o registro desta operação. Atualize a tela e tente novamente.');
            }

            if (! $request->isMethod('GET') && $status >= 500) {
                Log::error('Erro não tratado em operação web.', [
                    'status' => $status,
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'user_id' => $request->user()?->id,
                ]);

                return redirect()
                    ->back()
                    ->withInput($request->except(['password', 'password_confirmation', 'current_password']))
                    ->with('error', 'Não foi possível concluir esta operação agora. Verifique sua conexão e tente novamente.');
            }

            return $response;
        });
    })->create();
