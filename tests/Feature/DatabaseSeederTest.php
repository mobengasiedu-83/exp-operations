<?php

use App\Models\Dentist;
use App\Models\DentistNumber;
use App\Models\ReportDefinition;
use App\Models\ScreeningProtocol;
use App\Models\Setting;
use App\Models\User;
use App\Services\Reporting;
use Database\Seeders\SuperAdministratorSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('one seed publishes every piece of reference data the console needs', function () {
    $this->seed();

    expect(User::query()->where('email', SuperAdministratorSeeder::EMAIL)->sole()->hasRole('Super Administrator'))->toBeTrue()
        ->and(Dentist::query()->count())->toBe(7)
        ->and(DentistNumber::query()->where('status', 'approved')->count())->toBe(7)
        ->and(ScreeningProtocol::query()->where('is_active', true)->count())->toBe(1)
        ->and(ReportDefinition::query()->count())->toBe(count(Reporting::definitions()))
        ->and(Setting::query()->count())->toBeGreaterThanOrEqual(4)
        ->and(Role::findByName('Viewer', 'web')->hasPermissionTo('view viewer guides'))->toBeTrue()
        ->and(Role::findByName('Agent', 'web')->hasPermissionTo('create cases'))->toBeTrue()
        ->and(Role::findByName('Agent', 'web')->hasPermissionTo('view operations overview'))->toBeTrue()
        ->and(Role::findByName('Dentist', 'web')->hasPermissionTo('view operations overview'))->toBeTrue()
        ->and(Role::findByName('Viewer', 'web')->hasPermissionTo('view operations overview'))->toBeTrue()
        ->and(Role::findByName('Super Administrator', 'web')->hasPermissionTo('view super admin guides'))->toBeTrue()
        ->and(Permission::query()->count())->toBeGreaterThanOrEqual(53);
});

test('re-seeding live never duplicates reference data or re-enables a retired role', function () {
    $this->seed();

    $counts = [
        'permissions' => Permission::query()->count(),
        'roles' => Role::query()->count(),
        'dentists' => Dentist::query()->count(),
        'numbers' => DentistNumber::query()->count(),
        'protocols' => ScreeningProtocol::query()->count(),
        'reports' => ReportDefinition::query()->count(),
        'settings' => Setting::query()->count(),
    ];

    Role::findByName('Viewer', 'web')->syncPermissions(['view call history']);
    ReportDefinition::query()->where('key', 'daily-operations')->update(['enabled' => false]);

    $this->seed();

    expect(Permission::query()->count())->toBe($counts['permissions'])
        ->and(Role::query()->count())->toBe($counts['roles'])
        ->and(Dentist::query()->count())->toBe($counts['dentists'])
        ->and(DentistNumber::query()->count())->toBe($counts['numbers'])
        ->and(ScreeningProtocol::query()->count())->toBe($counts['protocols'])
        ->and(ReportDefinition::query()->count())->toBe($counts['reports'])
        ->and(Setting::query()->count())->toBe($counts['settings'])
        ->and(Role::findByName('Viewer', 'web')->hasPermissionTo('view viewer guides'))->toBeTrue()
        ->and(ReportDefinition::query()->where('key', 'daily-operations')->sole()->enabled)->toBeFalse();
});

test('the super administrator credentials live in the database, hashed and verified', function () {
    $this->seed();

    $admin = User::query()->where('email', SuperAdministratorSeeder::EMAIL)->sole();

    expect($admin->password)->not->toBe(SuperAdministratorSeeder::PASSWORD)
        ->and(Hash::check(SuperAdministratorSeeder::PASSWORD, $admin->password))->toBeTrue()
        ->and($admin->name)->toBe(SuperAdministratorSeeder::NAME)
        ->and($admin->active)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->hasRole('Super Administrator'))->toBeTrue();
});

test('re-running the administrator seeder restores the credentials without duplicating the account', function () {
    $this->seed();

    $admin = User::query()->where('email', SuperAdministratorSeeder::EMAIL)->sole();
    $admin->password = 'rotated-password';
    $admin->active = false;
    $admin->save();

    $this->seed(SuperAdministratorSeeder::class);

    $admin = User::query()->where('email', SuperAdministratorSeeder::EMAIL)->sole();

    expect(Hash::check(SuperAdministratorSeeder::PASSWORD, $admin->password))->toBeTrue()
        ->and(User::query()->where('email', SuperAdministratorSeeder::EMAIL)->count())->toBe(1)
        ->and($admin->active)->toBeFalse();
});

test('the seeded credentials sign in to the console', function () {
    $this->seed();

    $this->post(route('login.store'), [
        'email' => SuperAdministratorSeeder::EMAIL,
        'password' => SuperAdministratorSeeder::PASSWORD,
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
