<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Publishes the three role manuals and seeds the read-only Viewer role.
 *
 * Super Administrator is the only role that holds all three guide permissions.
 * Administrator keeps the agent and viewer manuals but never the Super
 * Administrator one, so "full access to every manual" stays a single-role
 * privilege. The Viewer is the blueprint's fourth role: read-only call history,
 * published reports and the dentist directory. It deliberately does not receive
 * `view pbx`, because that permission also reveals the live console and the
 * administration hub.
 */
class GuideAccessSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $agentGuide = Permission::findOrCreate('view agent guides', 'web');
        $viewerGuide = Permission::findOrCreate('view viewer guides', 'web');
        $superAdminGuide = Permission::findOrCreate('view super admin guides', 'web');

        // `view operations overview` is the one live-operations permission the
        // Viewer keeps: it renders the dashboard overview read-only. `view pbx`
        // still stays away from the role because it also reveals the live
        // console, PBX telemetry tools and the administration hub.
        $viewerPermissions = ['view call history', 'view all cases', 'view reports', 'view dentists', 'view operations overview'];

        foreach ($viewerPermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('Super Administrator', 'web')
            ->givePermissionTo([$agentGuide, $viewerGuide, $superAdminGuide]);

        Role::findOrCreate('Administrator', 'web')
            ->givePermissionTo([$agentGuide, $viewerGuide]);

        foreach (['Agent', 'Supervisor'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo($agentGuide);
        }

        Role::findOrCreate('Viewer', 'web')->syncPermissions([...$viewerPermissions, 'view viewer guides']);
    }
}
