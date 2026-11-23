<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `create cases` was added to the permission catalogue after the operational
 * roles had already been seeded, so deployed databases had no way to open a
 * case ("Add case details" refused every agent). Grant it to the roles that may
 * open one; `edit cases` still governs everything inside an existing case.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('create cases', 'web');

        $roles = [
            'Agent',
            'Supervisor',
            'Dental Assistant',
            'Platform Manager',
            'Lead Dentist / Clinical Supervisor',
            'Administrator',
            'Super Administrator',
        ];

        foreach (Role::query()->whereIn('name', $roles)->where('guard_name', 'web')->get() as $role) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Revoking the permission would lock agents out of opening cases again.
    }
};
