<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TransferAccessSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view transfers', 'edit transfers'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Super Administrator', 'Lead Dentist / Clinical Supervisor', 'Dental Assistant'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo(['view transfers', 'edit transfers']);
        }
        Role::findOrCreate('Platform Manager', 'web')->givePermissionTo('view transfers');
    }
}
