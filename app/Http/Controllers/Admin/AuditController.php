<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class AuditController extends Controller
{
    /**
     * Display a listing of audit log entries.
     */
    public function index(): Response
    {
        $this->authorize('view audit');

        $activities = Activity::query()
            ->with('causer')
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        return inertia('Admin/Audit/Index', [
            'activities' => $activities,
        ]);
    }
}
