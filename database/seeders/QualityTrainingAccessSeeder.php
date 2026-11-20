<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class QualityTrainingAccessSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = [
            'view quality assurance', 'manage quality assurance', 'approve quality assurance',
            'view training', 'manage training', 'authorise live calls',
            'view incidents', 'report incidents', 'manage incidents', 'close incidents',
        ];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('Super Administrator', 'web')->givePermissionTo($permissions);
        Role::findOrCreate('Platform Manager', 'web')->givePermissionTo([
            'view quality assurance', 'view training', 'manage training',
            'view incidents', 'report incidents', 'manage incidents', 'close incidents',
        ]);
        Role::findOrCreate('Lead Dentist / Clinical Supervisor', 'web')->givePermissionTo([
            'view quality assurance', 'manage quality assurance', 'approve quality assurance',
            'view training', 'manage training', 'authorise live calls',
            'view incidents', 'report incidents', 'manage incidents', 'close incidents',
        ]);
        Role::findOrCreate('QA Reviewer', 'web')->givePermissionTo([
            'view quality assurance', 'manage quality assurance', 'approve quality assurance',
            'view training', 'view incidents', 'report incidents',
        ]);
        Role::findOrCreate('Dental Assistant', 'web')->givePermissionTo([
            'view training', 'report incidents',
        ]);
        Role::findOrCreate('Executive / Management', 'web')->givePermissionTo([
            'view quality assurance', 'view training', 'view incidents',
        ]);
    }
}
