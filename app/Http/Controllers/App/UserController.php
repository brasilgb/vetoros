<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\App\Company;
use App\Models\App\OrderTechnicianAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantSequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class UserController extends Controller
{
    private function canManageTechnicianMaster(User $actor): bool
    {
        return $actor->isRoot() || $actor->isAdministrator();
    }

    private function resolveTechnicianMasterFlag(User $actor, array $data, ?User $target = null): bool
    {
        if ((int) ($data['roles'] ?? 0) !== User::ROLE_TECHNICIAN) {
            return false;
        }

        if (! $this->canManageTechnicianMaster($actor)) {
            return (bool) ($target?->can_view_all_orders ?? false);
        }

        return (bool) ($data['can_view_all_orders'] ?? false);
    }

    private function resolveCommissionPercentage(array $data): ?float
    {
        if ((int) ($data['roles'] ?? 0) !== User::ROLE_TECHNICIAN) {
            return null;
        }

        $value = $data['commission_percentage'] ?? null;

        return $value === null || $value === '' ? null : max(0, min(100, (float) $value));
    }

    private function storeAvatar(UserRequest $request, ?User $target = null): ?string
    {
        if (! $request->hasFile('avatar')) {
            return $target?->avatar;
        }

        $disk = Storage::disk('public');
        if (! $disk->directoryExists('avatars')) {
            $disk->makeDirectory('avatars');
        }

        if ($target?->avatar) {
            $disk->delete(str_replace('/storage/', '', $target->avatar));
        }

        $path = $request->file('avatar')->store('avatars', 'public');

        return '/storage/'.$path;
    }

    private function scopeUserListingByActor($query)
    {
        $authUser = Auth::user();

        if (! $authUser instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if (is_null($authUser->tenant_id) && $authUser->isRoot()) {
            return $query;
        }

        if ($authUser->isRoot()) {
            return $query->where(function ($query) use ($authUser) {
                $query->whereIn('roles', [User::ROLE_ADMIN, User::ROLE_OPERATOR, User::ROLE_TECHNICIAN])
                    ->orWhere('id', $authUser->id);
            });
        }

        if ($authUser->isAdministrator()) {
            return $query->whereIn('roles', [User::ROLE_ADMIN, User::ROLE_OPERATOR, User::ROLE_TECHNICIAN]);
        }

        if ($authUser->isOperator()) {
            return $query->where(function ($query) use ($authUser) {
                $query->where('roles', User::ROLE_TECHNICIAN)
                    ->orWhere('id', $authUser->id);
            });
        }

        return $query;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $search = $request->search;
        $query = $this->scopeUserListingByActor(User::query())->orderBy('id', 'DESC');
        if ($search) {
            $query->where('name', 'like', '%'.$search.'%');
        }
        $users = $query->paginate(\App\Support\Pagination::perPage())->withQueryString();
        $firstAdminId = User::where('roles', User::ROLE_ROOT_APP)->orderBy('id', 'asc')->value('id');

        return Inertia::render('app/users/index', ['users' => $users, 'firstAdminId' => $firstAdminId, 'search' => $search]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->authorize('create', User::class);

        return Inertia::render('app/users/create-user');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(UserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->all();
        $request->validated();
        Gate::authorize('assignRole', [User::class, $data['roles'] ?? null]);
        $data['password'] = Hash::make($request->password);
        $data['tenant_id'] = Auth::user()->tenant_id;
        $data['can_view_all_orders'] = $this->resolveTechnicianMasterFlag(Auth::user(), $data);
        $data['commission_percentage'] = $this->resolveCommissionPercentage($data);
        $data['avatar'] = $this->storeAvatar($request);
        $data['user_number'] = TenantSequence::next(User::class, 'user_number');
        Model::reguard();
        User::create($data);
        Model::unguard();

        return redirect()->route('app.users.index')->with('success', 'Usuário cadastrado com sucesso');
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user, Request $request)
    {
        $this->authorize('view', $user);

        return Inertia::render('app/users/edit-user', [
            'user' => $user,
            'page' => $request->page,
            'search' => $request->search,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user, Request $request)
    {
        $this->authorize('update', $user);

        return redirect()->route('app.users.show', [
            'user' => $user->id,
            'page' => $request->page,
            'search' => $request->search,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->all();
        $request->validated();
        if ((int) ($data['roles'] ?? $user->roles) !== (int) $user->roles) {
            Gate::authorize('assignRole', [User::class, $data['roles'] ?? null]);
        }
        $data['password'] = $request->password ? Hash::make($request->password) : $user->password;
        $data['tenant_id'] = $user->tenant_id;
        $data['can_view_all_orders'] = $this->resolveTechnicianMasterFlag(Auth::user(), $data, $user);
        $data['commission_percentage'] = $this->resolveCommissionPercentage($data);
        $data['avatar'] = $this->storeAvatar($request, $user);
        Model::reguard();
        $user->update($data);
        Model::unguard();

        return redirect()->route('app.users.show', ['user' => $user->id])->with('success', 'Usuário editado com sucesso');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        $hasAssignmentHistory = OrderTechnicianAssignment::withoutGlobalScopes()->where('technician_id', $user->id)->exists();

        if ($user->orders()->exists() || $user->schedules()->exists() || $hasAssignmentHistory) {
            return redirect()->route('app.users.index')->with(
                'error',
                'Não é possível excluir este usuário porque existem ordens ou agendamentos vinculados a ele. Desative-o em vez de excluir.'
            );
        }

        $user->delete();

        return redirect()->route('app.users.index')->with('success', 'Usuário excluido com sucesso!');
    }

    public function loginuser(Request $request)
    {
        $loginUserData = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|min:8',
        ]);
        $user = User::where('email', $loginUserData['email'])->first();
        if (! $user || ! Hash::check($loginUserData['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'result' => [],
            ], 401);
        }
        $token = $user->createToken($user->name.'-AuthToken')->plainTextToken;
        $company = $user->tenant_id
            ? Company::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->first(['shortname', 'companyname', 'logo'])
            : null;
        $tenant = $user->tenant_id
            ? Tenant::query()
                ->whereKey($user->tenant_id)
                ->first(['name', 'company'])
            : null;

        $companyName = $company?->shortname ?: $company?->companyname ?: $tenant?->company ?: $tenant?->name;
        $companyLogo = $company?->logo;
        $result = $user->toArray();
        $result['avatar_url'] = $user->avatar ? asset(ltrim($user->avatar, '/')) : null;

        return response()->json([
            'success' => true,
            'access_token' => $token,
            'result' => $result,
            'company' => [
                'name' => $companyName,
                'logo' => $companyLogo,
                'logo_url' => $companyLogo ? asset('storage/logos/'.$companyLogo) : asset('images/default.png'),
            ],
        ]);
    }

    public function logoutuser()
    {
        Auth::user()->tokens()->delete();

        return response()->json([
            'message' => 'logged out',
        ]);
    }
}
