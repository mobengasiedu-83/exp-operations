<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The post-sign-in landing page and the Operations overview it carries.
 *
 * Every role receives `view operations overview`, so the overview tiles, PBX
 * health card and active-call list are shown to all signed-in staff. The deeper
 * live console (and the Administration hub it links to) still needs `view pbx`.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('Dashboard', [
            'canViewOverview' => $user->canAny(['view operations overview', 'view pbx']),
            'canOpenConsole' => $user->canAny(['view operations overview', 'view pbx']),
            'canViewLiveDetails' => $user->can('view pbx'),
        ]);
    }
}
