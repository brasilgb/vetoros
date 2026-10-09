<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

/**
 * Cadastro de usuários pelo RootAdmin. Papel e empresa andam juntos: RootSystem é o
 * único papel sem empresa; os demais exigem um tenant existente. Conceder RootSystem
 * (criar ou promover) exige a senha do RootAdmin, para não acontecer por engano.
 */
class AdminUserRequest extends FormRequest
{
    public const ROLES = [
        User::ROLE_ROOT_SYSTEM,
        User::ROLE_ROOT_APP,
        User::ROLE_ADMIN,
        User::ROLE_OPERATOR,
        User::ROLE_TECHNICIAN,
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->isRootAdmin();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $editing = $this->editingUser();
        $isRootSystem = $this->isRootSystemRole();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($editing?->id)],
            'telephone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'roles' => ['required', Rule::in(self::ROLES)],
            'tenant_id' => match (true) {
                $isRootSystem => ['prohibited'],
                $this->keepsLegacyRootApp() => ['nullable', 'integer', Rule::exists('tenants', 'id')],
                default => ['required', 'integer', Rule::exists('tenants', 'id')],
            },
            'status' => ['sometimes', 'boolean'],
            'password' => $editing
                ? ['nullable', 'min:8', 'confirmed', Rules\Password::defaults()]
                : ['required', 'min:8', 'confirmed', Rules\Password::defaults()],
            'password_confirmation' => $editing ? ['nullable', 'min:8'] : ['required', 'min:8'],
            'admin_password' => $this->grantsRootSystem()
                ? ['required', 'current_password']
                : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'tenant_id.prohibited' => 'O RootSystem não pertence a uma empresa. Remova a empresa ou escolha outra função.',
            'tenant_id.required' => 'Selecione a empresa do usuário. Só o RootSystem fica sem empresa.',
            'admin_password.required' => 'Informe a sua senha para conceder o acesso de RootSystem.',
            'admin_password.current_password' => 'Senha incorreta.',
        ];
    }

    public function attributes(): array
    {
        return [
            'tenant_id' => 'empresa',
            'name' => 'nome',
            'email' => 'e-mail',
            'roles' => 'função',
            'password' => 'senha',
            'password_confirmation' => 'confirmar senha',
            'admin_password' => 'sua senha',
        ];
    }

    /**
     * Somente os campos que o RootAdmin pode gravar (sem atribuição em massa do request).
     *
     * @return array<string, mixed>
     */
    public function userData(): array
    {
        $data = collect($this->validated())
            ->only(['name', 'email', 'telephone', 'whatsapp', 'roles', 'status'])
            ->all();
        $data['roles'] = (int) $data['roles'];
        $tenantId = $this->validated('tenant_id');
        $data['tenant_id'] = $this->isRootSystemRole() || blank($tenantId) ? null : (int) $tenantId;

        if (filled($this->validated('password'))) {
            $data['password'] = $this->validated('password');
        }

        return $data;
    }

    public function isRootSystemRole(): bool
    {
        return (int) $this->input('roles') === User::ROLE_ROOT_SYSTEM;
    }

    /** Criar um RootSystem ou promover alguém que ainda não era RootAdmin. */
    public function grantsRootSystem(): bool
    {
        if (! $this->isRootSystemRole()) {
            return false;
        }

        $editing = $this->editingUser();

        return ! $editing || ! $editing->isRootAdmin();
    }

    /** RootApp sem empresa que já é RootAdmin (contas antigas) pode continuar assim na edição. */
    private function keepsLegacyRootApp(): bool
    {
        $editing = $this->editingUser();

        return (int) $this->input('roles') === User::ROLE_ROOT_APP
            && $editing !== null
            && $editing->isRootAdmin()
            && (int) $editing->roles === User::ROLE_ROOT_APP;
    }

    private function editingUser(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }
}
