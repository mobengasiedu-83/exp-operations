<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class KnowledgeBankAccessSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['view knowledge bank', 'manage knowledge bank', 'approve clinical knowledge', 'approve brand knowledge'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('Super Administrator', 'web')->givePermissionTo($permissions);
        Role::findOrCreate('Platform Manager', 'web')->givePermissionTo([
            'view knowledge bank', 'manage knowledge bank',
        ]);
        Role::findOrCreate('Lead Dentist / Clinical Supervisor', 'web')->givePermissionTo([
            'view knowledge bank', 'manage knowledge bank', 'approve clinical knowledge',
        ]);
        Role::findOrCreate('Dental Assistant', 'web')->givePermissionTo(['view knowledge bank']);
        Role::findOrCreate('QA Reviewer', 'web')->givePermissionTo(['view knowledge bank']);
        Role::findOrCreate('Executive / Management', 'web')->givePermissionTo(['view knowledge bank']);
    }
}
