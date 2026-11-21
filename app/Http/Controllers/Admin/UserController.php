<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\SetupPassword;
use App\Notifications\VerifyUserEmail;
use App\Notifications\WelcomeUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(): Response
    {
        $this->authorize('view users');

        $users = User::with('roles')->orderBy('name')->paginate(25);

        return inertia('Admin/Users/Index', [
            'users' => $users,
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create users');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', Password::defaults(), 'confirmed'],
            'active' => ['sometimes', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['exists:roles,name'],
        ]);

        $this->authorizeRoles($request, $validated['roles'] ?? []);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'active' => $validated['active'] ?? true,
        ]);

        if (! empty($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }

        $user->notify((new WelcomeUser)->afterCommit());
        $user->notify((new VerifyUserEmail)->afterCommit());
        $user->notify((new SetupPassword(PasswordBroker::createToken($user)))->afterCommit());

        return redirect()->route('admin.users.index')
            ->with('success', 'User created successfully. Welcome, verification, and password setup emails have been queued.');
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('edit users');
        $this->authorizeTarget($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', Password::defaults(), 'confirmed'],
            'active' => ['sometimes', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['exists:roles,name'],
        ]);

        $this->authorizeRoles($request, $validated['roles'] ?? []);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'active' => $validated['active'] ?? $user->active,
        ]);

        if (isset($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        if (isset($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }

        return redirect()->route('admin.users.index')
            ->with('success', 'User updated successfully.');
    }

    private function authorizeTarget(User $user): void
    {
        foreach ($user->getAllPermissions() as $permission) {
            $this->authorize($permission->name);
        }
    }

    /** @param list<string> $roles */
    private function authorizeRoles(Request $request, array $roles): void
    {
        foreach (Role::whereIn('name', $roles)->with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                abort_unless($request->user()->can($permission->name), 403);
            }
        }
    }

    /**
     * Deactivate the specified user.
     */
    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete users');
        $this->authorizeTarget($user);

        $user->update(['active' => false]);

        return redirect()->route('admin.users.index')
            ->with('success', 'User deactivated successfully.');
    }
}
