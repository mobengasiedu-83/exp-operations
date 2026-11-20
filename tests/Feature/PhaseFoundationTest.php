<?php

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('user editors cannot grant permissions they do not hold', function () {
    Permission::findOrCreate('edit users');
    Permission::findOrCreate('edit settings');
    $privileged = Role::findOrCreate('Privileged');
    $privileged->givePermissionTo('edit settings');
    $editor = User::factory()->create();
    $editor->givePermissionTo('edit users');
    $target = User::factory()->create();

    $this->actingAs($editor)->put(route('admin.users.update', $target), [
        'name' => $target->name, 'email' => $target->email, 'roles' => ['Privileged'],
    ])->assertForbidden();

    expect($target->fresh()->hasRole('Privileged'))->toBeFalse();
});

test('admin changes produce audit records without passwords', function () {
    Permission::findOrCreate('create users');
    $admin = User::factory()->create();
    $admin->givePermissionTo('create users');
    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Operator', 'email' => 'operator@example.test',
        'password' => 'sample-password-123', 'password_confirmation' => 'sample-password-123',
    ])->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseHas('users', ['email' => 'operator@example.test']);
    $activity = Activity::latest('id')->firstOrFail();
    expect($activity->causer_id)->toBe($admin->id);
    expect($activity->properties->toJson())->not->toContain('sample-password-123');
});

test('service hours validate closing time and persist authorised changes', function () {
    Permission::findOrCreate('edit settings');
    $admin = User::factory()->create();
    $admin->givePermissionTo('edit settings');
    $this->actingAs($admin)->post(route('admin.service-hours.store'), [
        'name' => 'Pilot', 'opens_at' => '08:00', 'closes_at' => '07:00', 'is_closed' => false,
    ])->assertSessionHasErrors('closes_at');
    $this->assertDatabaseMissing('service_hours', ['name' => 'Pilot']);

    $this->post(route('admin.service-hours.store'), [
        'name' => 'Pilot', 'opens_at' => '08:00', 'closes_at' => '17:00', 'is_closed' => false,
    ])->assertRedirect();
    $this->assertDatabaseHas('service_hours', ['name' => 'Pilot', 'is_closed' => false]);
});

test('pbx viewers cannot change connection configuration', function () {
    Permission::findOrCreate('view pbx');
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view pbx');
    $this->actingAs($viewer)->post(route('admin.pbx-connections.store'), [
        'name' => 'Forbidden', 'host' => '127.0.0.1',
    ])->assertForbidden();
    $this->assertDatabaseMissing('pbx_connections', ['name' => 'Forbidden']);
});

test('user editors cannot change a more privileged account password', function () {
    Permission::findOrCreate('edit users');
    Permission::findOrCreate('edit settings');
    $editor = User::factory()->create();
    $editor->givePermissionTo('edit users');
    $target = User::factory()->create();
    $target->givePermissionTo(['edit users', 'edit settings']);
    $originalPassword = $target->password;
    $this->actingAs($editor)->put(route('admin.users.update', $target), [
        'name' => $target->name, 'email' => $target->email,
        'password' => 'hijack-password', 'password_confirmation' => 'hijack-password',
    ])->assertForbidden();
    expect($target->fresh()->password)->toBe($originalPassword);
});

test('inactive and unprivileged users cannot access Horizon even locally', function () {
    $request = Request::create('/horizon');
    $user = User::factory()->create();
    $request->setUserResolver(fn () => $user);
    expect(Horizon::check($request))->toBeFalse();
    Permission::findOrCreate('edit settings');
    $user->givePermissionTo('edit settings');
    expect(Horizon::check($request))->toBeTrue();
    $user->update(['active' => false]);
    expect(Horizon::check($request))->toBeFalse();
});
