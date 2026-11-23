<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Operations overview on the dashboard is now read for every role, so this
 * grants the new `view operations overview` permission to every existing role
 * (including roles an administrator created outside the seeders). `view pbx`
 * is untouched: the live console, PBX telemetry tools and the Administration
 * hub still require it.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('view operations overview', 'web');

        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Leave the grant in place; removing it would hide the overview from
        // every role on a rollback.
    }
};
