<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\UserRegisteredMail;
use App\Models\Admin\Plan;
use App\Models\App\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RegisteredUserController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/register', [
            'initialData' => array_filter($request->only(['name', 'email', 'whatsapp'])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {

        $request->validate([
            'name' => 'required|string|max:255',
            'company' => 'required|string|max:150|unique:tenants,company',
            'cnpj' => 'required|string|max:20|unique:tenants,cnpj',
            'phone' => 'required|string|max:20',
            'whatsapp' => 'required|string|max:20',
            'email' => 'required|string|lowercase|email|max:150|unique:users,email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'accepted_terms' => ['accepted'],
        ], [
            'accepted_terms.accepted' => 'Você precisa aceitar os Termos de Uso para criar sua conta.',
        ]);

        $user = DB::transaction(function () use ($request) {
            $trialPlan = Plan::query()
                ->with('periods')
                ->get()
                ->first(fn (Plan $plan) => $plan->isTrial());
            $trialPeriod = $trialPlan?->preferredPeriod();

            $tenant = Tenant::create([
                'name' => $request->name,
                'company' => $request->company,
                'cnpj' => $request->cnpj,
                'email' => $request->email,
                'phone' => $request->phone,
                'whatsapp' => $request->whatsapp,
                'status' => 1,
                'plan_id' => $trialPlan?->id,
                'period_id' => $trialPeriod?->id,
                'subscription_status' => 'active',
                'expires_at' => now()->addDays(14),
            ]);

            Company::create([
                'tenant_id' => $tenant->id,
                'companyname' => $request->company,
                'cnpj' => $request->cnpj,
                'telephone' => $request->phone,
                'whatsapp' => $request->whatsapp,
                'email' => $request->email,
            ]);

            return User::create([
                'name' => $request->name,
                'user_number' => 1,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'telephone' => $request->phone,
                'whatsapp' => $request->whatsapp,
                'status' => 1,
                'roles' => 9,
                'tenant_id' => $tenant->id,
            ]);
        });

        event(new Registered($user));

        Auth::login($user);

        try {
            Mail::to($user->email)->send(new UserRegisteredMail($user));
        } catch (Throwable $exception) {
            Log::warning('Falha ao enviar e-mail de boas-vindas no cadastro.', [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'email' => $user->email,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('app.dashboard')
                ->with('success', 'Conta criada com sucesso! Você possui 14 dias de acesso grátis para testes.')
                ->with('error', 'Não foi possível enviar o e-mail de confirmação agora. Verifique a conexão ou a configuração SMTP e tente novamente mais tarde.');
        }

        return redirect()
            ->route('app.dashboard')
            ->with(
                'message',
                'Conta criada com sucesso! Você possui 14 dias de acesso grátis para testes.'
            );
    }
}
