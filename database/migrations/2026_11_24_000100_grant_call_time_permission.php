<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Call time is a read-only timing report (wait, agent and dentist time) that
 * every role may study, so this publishes `view call time` for every existing
 * role, including roles an administrator created outside the seeders. `view
 * pbx` is untouched: the live console tools keep their own permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('view call time', 'web');

        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Leave the grant in place; removing it would hide the menu on a
        // rollback while the routes still exist.
    }
};
