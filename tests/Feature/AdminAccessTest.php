<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('guests are redirected to login for admin routes', function () {
    $routes = [
        route('admin.users.index'),
        route('admin.roles.index'),
        route('admin.permissions.index'),
        route('admin.settings.index'),
        route('admin.sessions.index'),
        route('admin.audit.index'),
    ];

    foreach ($routes as $route) {
        $response = $this->get($route);
        $response->assertRedirect(route('login'));
    }
});

test('authenticated user without permissions cannot access admin sections', function () {
    $user = User::factory()->create(['active' => true]);
    $this->actingAs($user);

    $routes = [
        route('admin.users.index'),
        route('admin.roles.index'),
        route('admin.permissions.index'),
        route('admin.settings.index'),
        route('admin.sessions.index'),
        route('admin.audit.index'),
    ];

    foreach ($routes as $route) {
        $response = $this->get($route);
        $response->assertStatus(403);
    }
});

test('super administrator can access all admin sections', function () {
    $permissions = [
        'view users', 'create users', 'edit users', 'delete users',
        'view roles', 'create roles', 'edit roles', 'delete roles',
        'view permissions', 'view settings', 'edit settings',
        'view sessions', 'terminate sessions', 'view audit',
    ];
    foreach ($permissions as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    $adminRole = Role::firstOrCreate(['name' => 'Super Administrator', 'guard_name' => 'web']);
    $adminRole->givePermissionTo(Permission::all());

    $user = User::factory()->create([
        'name' => 'Admin',
        'email' => 'admin@test.local',
        'active' => true,
    ]);
    $user->assignRole('Super Administrator');
    $this->actingAs($user);

    $routes = [
        route('admin.users.index'),
        route('admin.roles.index'),
        route('admin.permissions.index'),
        route('admin.settings.index'),
        route('admin.sessions.index'),
        route('admin.audit.index'),
    ];

    foreach ($routes as $route) {
        $response = $this->get($route);
        $response->assertOk();
    }
});

test('inactive user cannot access admin sections', function () {
    $permissions = ['view users', 'view roles', 'view permissions', 'view settings', 'view sessions', 'view audit'];
    foreach ($permissions as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    $adminRole = Role::firstOrCreate(['name' => 'Super Administrator', 'guard_name' => 'web']);
    $adminRole->givePermissionTo(Permission::all());

    $user = User::factory()->create([
        'name' => 'Inactive Admin',
        'email' => 'inactive@test.local',
        'active' => false,
    ]);
    $user->assignRole('Super Administrator');
    $this->actingAs($user);

    $response = $this->get(route('admin.users.index'));
    $response->assertStatus(403);
});

test('user with only view users permission can access users index', function () {
    $viewUsers = Permission::firstOrCreate(['name' => 'view users', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'Limited User Manager', 'guard_name' => 'web']);
    $role->givePermissionTo($viewUsers);

    $user = User::factory()->create(['active' => true]);
    $user->assignRole($role);
    $this->actingAs($user);

    $response = $this->get(route('admin.users.index'));
    $response->assertOk();

    // Cannot access roles without permission
    $response = $this->get(route('admin.roles.index'));
    $response->assertStatus(403);
});

test('super administrator automatically receives new abilities and frontend permissions', function () {
    $permission = Permission::findOrCreate('manage future feature', 'web');
    $role = Role::findOrCreate('Super Administrator', 'web');
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can($permission->name))->toBeTrue();
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);
    $shared = app(HandleInertiaRequests::class)->share($request);
    expect($shared['auth']['permissions'])->toContain($permission->name);

    $ordinary = User::factory()->create();
    expect($ordinary->can($permission->name))->toBeFalse();
});
