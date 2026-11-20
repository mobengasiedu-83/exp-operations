<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class WorkspaceAccessSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['view cases', 'edit cases', 'view all cases', 'review cases', 'manage screening protocols'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('Super Administrator', 'web')->givePermissionTo($permissions);
        Role::findOrCreate('Lead Dentist / Clinical Supervisor', 'web')->givePermissionTo([
            'view cases', 'edit cases', 'view all cases', 'review cases', 'manage screening protocols',
        ]);
        Role::findOrCreate('Dental Assistant', 'web')->givePermissionTo(['view cases', 'edit cases']);
        $this->call(ScreeningProtocolSeeder::class);
    }
}
