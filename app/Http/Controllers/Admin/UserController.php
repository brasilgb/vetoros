<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantSequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->search;
        $query = User::orderBy('id', 'DESC');
        if ($search) {
            $query->where('name', 'like', '%'.$search.'%');
        }
        $users = $query
            ->whereIn('roles', [User::ROLE_ROOT_APP, User::ROLE_ROOT_SYSTEM])
            ->with('tenant')
            ->paginate(11);

        return Inertia::render('admin/users/index', ['users' => $users]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $tenants = Tenant::get();

        return Inertia::render('admin/users/create-user', ['tenants' => $tenants]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AdminUserRequest $request): RedirectResponse
    {
        $data = $request->userData();
        $data['user_number'] = TenantSequence::next(
            User::class,
            'user_number',
            $data['tenant_id']
        );
        Model::reguard();
        User::create($data);
        Model::unguard();

        return redirect()->route('admin.users.index')->with('success', 'Usuário cadastrado com sucesso');
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        $tenants = Tenant::get();

        return Inertia::render('admin/users/edit-user', ['user' => $user, 'tenants' => $tenants]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user)
    {
        return redirect()->route('admin.users.show', ['user' => $user->id]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AdminUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->userData();

        // O RootAdmin não pode tirar o próprio acesso (troca de função ou de empresa).
        if ($user->is($request->user()) && ($data['tenant_id'] !== null || ! in_array($data['roles'], [User::ROLE_ROOT_SYSTEM, User::ROLE_ROOT_APP], true))) {
            return back()->withErrors(['roles' => 'Você não pode remover o seu próprio acesso de RootAdmin.']);
        }

        Model::reguard();
        $user->update($data);
        Model::unguard();

        return redirect()->route('admin.users.show', ['user' => $user->id])->with('success', 'Usuário editado com sucesso');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'Você não pode excluir o seu próprio usuário.');
        }

        if ($user->isRootAdmin() && User::query()->whereNull('tenant_id')->whereIn('roles', [User::ROLE_ROOT_SYSTEM, User::ROLE_ROOT_APP])->count() <= 1) {
            return back()->with('error', 'Não é possível excluir o último RootAdmin.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'Usuário excluido com sucesso!');
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

        return response()->json([
            'success' => true,
            'access_token' => $token,
            'result' => $user,
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
