<?php

use App\Models\CdrRecord;
use App\Models\CelRecord;
use App\Models\IntegrationHealth;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('guests are redirected to login for PBX routes', function () {
    $routes = [
        route('admin.pbx-connections.index'),
        route('admin.ami-health.index'),
        route('admin.integration-logs.index'),
        route('admin.cdr-cel.index'),
        route('admin.connection-health.index'),
        route('admin.structured-logs.index'),
    ];

    foreach ($routes as $route) {
        $response = $this->get($route);
        $response->assertRedirect(route('login'));
    }
});

test('authenticated user without pbx permission cannot access PBX sections', function () {
    $user = User::factory()->create(['active' => true]);
    $this->actingAs($user);

    $routes = [
        route('admin.pbx-connections.index'),
        route('admin.ami-health.index'),
        route('admin.integration-logs.index'),
        route('admin.cdr-cel.index'),
        route('admin.connection-health.index'),
        route('admin.structured-logs.index'),
    ];

    foreach ($routes as $route) {
        $response = $this->get($route);
        $response->assertStatus(403);
    }
});

test('user with view pbx permission can access PBX sections', function () {
    $viewPbx = Permission::firstOrCreate(['name' => 'view pbx', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'PBX Viewer', 'guard_name' => 'web']);
    $role->givePermissionTo($viewPbx);

    $user = User::factory()->create(['active' => true]);
    $user->assignRole($role);
    $this->actingAs($user);

    $response = $this->get(route('admin.pbx-connections.index'));
    $response->assertOk();

    $response = $this->get(route('admin.ami-health.index'));
    $response->assertOk();

    $response = $this->get(route('admin.integration-logs.index'));
    $response->assertOk();

    $response = $this->get(route('admin.cdr-cel.index'));
    $response->assertOk();
    $this->get(route('admin.connection-health.index'))->assertOk();
    $this->get(route('admin.structured-logs.index'))->assertOk();
});

test('pbx connection can be created and retrieved', function () {
    $viewPbx = Permission::firstOrCreate(['name' => 'view pbx', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'PBX Admin', 'guard_name' => 'web']);
    $role->givePermissionTo($viewPbx);
    $role->givePermissionTo(Permission::findOrCreate('edit settings'));

    $user = User::factory()->create(['active' => true]);
    $user->assignRole($role);
    $this->actingAs($user);

    $response = $this->post(route('admin.pbx-connections.store'), [
        'name' => 'Test PBX',
        'host' => '10.10.20.50',
        'ami_port' => 5038,
        'status' => 'disconnected',
    ]);

    $response->assertRedirect(route('admin.pbx-connections.index'));

    $this->assertDatabaseHas('pbx_connections', [
        'name' => 'Test PBX',
        'host' => '10.10.20.50',
        'ami_port' => 5038,
    ]);
});

test('integration health can be recorded', function () {
    $integrationHealth = IntegrationHealth::record('ami_listener', 'healthy', null, [
        'connection_status' => 'connected',
    ]);

    expect($integrationHealth->component)->toBe('ami_listener');
    expect($integrationHealth->status)->toBe('healthy');
    expect($integrationHealth->metadata['connection_status'])->toBe('connected');

    $this->assertDatabaseHas('integration_health', [
        'component' => 'ami_listener',
        'status' => 'healthy',
    ]);
});

test('cdr record can be created with unique id', function () {
    $cdr = CdrRecord::create([
        'uniqueid' => '1234567890.123',
        'linkedid' => '1234567890.123',
        'src' => '204',
        'dst' => '0800123456',
        'disposition' => 'ANSWERED',
        'duration' => 120,
        'billsec' => 115,
    ]);

    expect($cdr->uniqueid)->toBe('1234567890.123');
    expect($cdr->duration)->toBe(120);

    $this->assertDatabaseHas('cdr_records', [
        'uniqueid' => '1234567890.123',
        'disposition' => 'ANSWERED',
    ]);
});

test('cel record can be created with unique id', function () {
    $cel = CelRecord::create([
        'uniqueid' => '1234567890.124',
        'linkedid' => '1234567890.124',
        'event_type' => 'BridgeEnter',
        'channel' => 'PJSIP/204-00000001',
    ]);

    expect($cel->uniqueid)->toBe('1234567890.124');
    expect($cel->event_type)->toBe('BridgeEnter');

    $this->assertDatabaseHas('cel_records', [
        'uniqueid' => '1234567890.124',
        'event_type' => 'BridgeEnter',
    ]);
});
