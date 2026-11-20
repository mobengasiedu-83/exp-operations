<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConnectionHealth;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionHealthController extends Controller
{
    public function index(): Response
    {
        $this->authorize('view pbx');

        return Inertia::render('Admin/ConnectionHealth/Index', [
            'health' => ConnectionHealth::with('connection')->latest('updated_at')->paginate(25),
            'staleSeconds' => config('services.exp_pbx.heartbeat_stale_seconds', 90),
        ]);
    }
}
