<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FollowUpAccessSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['view callbacks', 'edit callbacks', 'view referrals', 'edit referrals'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Super Administrator', 'Lead Dentist / Clinical Supervisor', 'Dental Assistant'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo($permissions);
        }
        Role::findOrCreate('Platform Manager', 'web')->givePermissionTo(['view callbacks', 'view referrals']);
        Role::findOrCreate('Executive / Management', 'web')->givePermissionTo(['view callbacks', 'view referrals']);
    }
}
