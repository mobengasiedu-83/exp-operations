<?php

use App\Models\User;
use App\Services\PrimaryNavigation;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function navigationUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('the shell exposes the dashboard plus the Phase 1 menus filtered by permission', function () {
    $navigation = app(PrimaryNavigation::class);

    expect($navigation->forUser(null))->toBe([])
        ->and(collect($navigation->forUser(navigationUser([])))->pluck('key')->all())->toBe(['dashboard'])
        ->and(collect($navigation->forUser(navigationUser(['view cases'])))->pluck('key')->all())->toBe(['dashboard', 'workspace'])
        ->and(collect($navigation->forUser(navigationUser(['view call history'])))->pluck('key')->all())->toBe(['dashboard', 'call-history'])
        ->and(collect($navigation->forUser(navigationUser(['view dentists'])))->pluck('key')->all())->toBe(['dashboard', 'dentists'])
        ->and(collect($navigation->forUser(navigationUser(['view audit'])))->pluck('key')->all())->toBe(['dashboard', 'administration']);

    $administrator = navigationUser([
        'view cases', 'view call history', 'view dentists', 'view users',
        'view roles', 'view permissions', 'view settings', 'view sessions',
        'view audit', 'view pbx', 'manage screening protocols',
    ]);

    $menus = collect($navigation->forUser($administrator));

    expect($menus->pluck('key')->all())->toBe(['dashboard', 'workspace', 'call-history', 'dentists', 'administration'])
        ->and($menus->firstWhere('key', 'dashboard'))->toMatchArray([
            'title' => 'Dashboard',
            'href' => route('dashboard'),
        ]);
});

test('the calls menu exposes the agent and did submenus', function () {
    $menus = collect(app(PrimaryNavigation::class)->forUser(navigationUser(['view call history'])));

    $calls = $menus->firstWhere('key', 'call-history');

    expect($calls)->not->toBeNull()
        ->and(collect($calls['children'])->pluck('key')->all())->toBe(['agent-calls', 'did-calls', 'all-calls'])
        ->and($calls['children'][0]['href'])->toBe(route('calls.agent'))
        ->and($calls['children'][1]['href'])->toBe(route('calls.did'));
});

test('an agent sees the dashboard and operational menus without administration', function () {
    $agent = navigationUser(['view cases', 'edit cases', 'view call history', 'view dentists']);

    $this->withoutVite();

    $this->actingAs($agent)->get(route('workspace.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Workspace/Index')
            ->has('navigation', 4)
            ->where('navigation.0.key', 'dashboard')
            ->where('navigation.1.key', 'workspace')
            ->where('navigation.2.key', 'call-history')
            ->where('navigation.3.key', 'dentists'));
});

test('every signed-in account gets a working dashboard menu entry', function () {
    $user = navigationUser([]);

    $this->withoutVite();

    $menus = collect(app(PrimaryNavigation::class)->forUser($user));

    expect($menus->pluck('key')->all())->toBe(['dashboard'])
        ->and($menus->first()['href'])->toBe(route('dashboard'));

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('navigation.0.key', 'dashboard')
            ->where('navigation.0.title', 'Dashboard'));
});

test('the administration hub links the retained configuration modules only to permitted users', function () {
    $administrator = navigationUser([
        'view users', 'view roles', 'view permissions', 'view settings',
        'view sessions', 'view audit', 'view pbx', 'view dentists',
        'manage screening protocols',
    ]);

    $this->withoutVite();

    $this->actingAs($administrator)->get(route('administration.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $sections = collect($page->toArray()['props']['sections'])->pluck('key')->all();

            expect($sections)->toContain(
                'users', 'roles', 'permissions', 'sessions', 'questionnaires',
                'dentists', 'dentist-numbers', 'availability', 'settings',
                'service-hours', 'integration-logs', 'audit',
            );
        });

    $this->actingAs(navigationUser(['view audit']))->get(route('administration.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            expect(collect($page->toArray()['props']['sections'])->pluck('key')->all())->toBe(['audit']);
        });
});

test('phase 1 endpoints reject guests and authenticated users without the required permission', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));

    $this->actingAs(navigationUser(['view cases']))->get(route($routeName))->assertForbidden();
})->with([
    'administration hub' => 'administration.index',
    'call history' => 'calls.index',
    'dentist directory' => 'dentists.index',
    'questionnaire administration' => 'screening-protocols.index',
    'user administration' => 'admin.users.index',
    'pbx configuration' => 'admin.pbx-connections.index',
]);

test('an inactive account cannot use the simplified shell', function () {
    $agent = navigationUser(['view cases']);
    $agent->update(['active' => false]);

    $this->actingAs($agent)->get(route('workspace.index'))->assertForbidden();
});
