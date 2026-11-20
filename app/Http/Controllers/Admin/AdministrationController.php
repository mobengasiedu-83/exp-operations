<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PrimaryNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AdministrationController extends Controller
{
    /**
     * Configuration hub for users, questionnaire versions, dentists and PBX settings.
     */
    public function index(Request $request, PrimaryNavigation $navigation): Response
    {
        $user = $request->user();
        abort_unless($user !== null && $navigation->canAdminister($user), 403);

        $sections = [
            ['key' => 'users', 'title' => 'Users & roles', 'description' => 'Create agent, supervisor and administrator accounts.', 'href' => route('admin.users.index'), 'visible' => $user->can('view users')],
            ['key' => 'roles', 'title' => 'Roles & permissions', 'description' => 'Review the permissions behind each role.', 'href' => route('admin.roles.index'), 'visible' => $user->can('view roles')],
            ['key' => 'permissions', 'title' => 'Permission catalogue', 'description' => 'Inspect the server-side permission behind each endpoint.', 'href' => route('admin.permissions.index'), 'visible' => $user->can('view permissions')],
            ['key' => 'sessions', 'title' => 'Active sessions', 'description' => 'Review and terminate signed-in sessions.', 'href' => route('admin.sessions.index'), 'visible' => $user->can('view sessions')],
            ['key' => 'questionnaires', 'title' => 'Questions & versions', 'description' => 'Version question types, required and conditional rules, and publish the active questionnaire.', 'href' => route('screening-protocols.index'), 'visible' => $user->can('manage screening protocols')],
            ['key' => 'dentists', 'title' => 'Dentist directory', 'description' => 'Maintain dentist records and approved transfer numbers.', 'href' => route('dentists.index'), 'visible' => $user->can('view dentists')],
            ['key' => 'dentist-numbers', 'title' => 'Dentist numbers', 'description' => 'Approve or block E.164 mobile numbers and choose the primary destination.', 'href' => route('dentists.numbers'), 'visible' => $user->can('view dentists')],
            ['key' => 'dentist-credentials', 'title' => 'Dentist credentials', 'description' => 'Track registration and credential expiry before transfer selection.', 'href' => route('dentists.credentials'), 'visible' => $user->can('view dentists')],
            ['key' => 'availability', 'title' => 'Manual availability', 'description' => 'Record available, unavailable and busy status by hand.', 'href' => route('dentists.availability'), 'visible' => $user->can('view dentists')],
            ['key' => 'rosters', 'title' => 'Dentist rosters', 'description' => 'Publish on-call shifts and recorded leave windows.', 'href' => route('dentists.rosters'), 'visible' => $user->can('view dentists')],
            ['key' => 'performance', 'title' => 'Dentist performance', 'description' => 'Observed transfer evidence with explicit missing-data states.', 'href' => route('dentists.performance'), 'visible' => $user->can('view dentists')],
            ['key' => 'settings', 'title' => 'Service settings', 'description' => 'Opening hours and general service configuration.', 'href' => route('admin.settings.index'), 'visible' => $user->can('view settings')],
            ['key' => 'service-hours', 'title' => 'Service hours', 'description' => 'Opening, closing and closed-day schedule used by the workspace.', 'href' => route('admin.service-hours.index'), 'visible' => $user->can('view settings')],
            ['key' => 'pbx', 'title' => 'PBX connections', 'description' => 'AMI connection settings and read-only correlation sources.', 'href' => route('admin.pbx-connections.index'), 'visible' => $user->can('view pbx')],
            ['key' => 'ami-health', 'title' => 'AMI health', 'description' => 'Listener heartbeat and PBX acceptance diagnostics.', 'href' => route('admin.ami-health.index'), 'visible' => $user->can('view pbx')],
            ['key' => 'integration-logs', 'title' => 'Integration logs', 'description' => 'Sanitised listener and reconciliation event records.', 'href' => route('admin.integration-logs.index'), 'visible' => $user->can('view pbx')],
            ['key' => 'connection-health', 'title' => 'Connection health', 'description' => 'Read-only checks for PBX, Redis and queue dependencies.', 'href' => route('admin.connection-health.index'), 'visible' => $user->can('view pbx')],
            ['key' => 'structured-logs', 'title' => 'Structured logs', 'description' => 'Recent application log entries without caller content.', 'href' => route('admin.structured-logs.index'), 'visible' => $user->can('view pbx')],
            ['key' => 'cdr-cel', 'title' => 'CDR / CEL records', 'description' => 'Reconciliation evidence exported from the PBX.', 'href' => route('admin.cdr-cel.index'), 'visible' => $user->can('view pbx')],
            ['key' => 'audit', 'title' => 'Audit log', 'description' => 'Administration changes, approvals and restricted access records.', 'href' => route('admin.audit.index'), 'visible' => $user->can('view audit')],
        ];

        return Inertia::render('Admin/Index', [
            'sections' => array_values(array_filter($sections, fn (array $section): bool => $section['visible'])),
            'roles' => Role::query()->withCount('users')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name, 'users' => $role->users_count])
                ->values()->all(),
        ]);
    }
}
