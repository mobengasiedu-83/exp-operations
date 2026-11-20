<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

class SessionController extends Controller
{
    /**
     * Display a listing of active sessions.
     */
    public function index(): Response
    {
        $this->authorize('view sessions');

        $sessions = DB::table('sessions')
            ->join('users', 'sessions.user_id', '=', 'users.id')
            ->select('sessions.id', 'sessions.user_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.name as user_name', 'users.email as user_email')
            ->orderBy('sessions.last_activity', 'desc')
            ->paginate(25);

        return inertia('Admin/Sessions/Index', [
            'sessions' => $sessions,
        ]);
    }

    /**
     * Terminate the specified session.
     */
    public function destroy(string $id): RedirectResponse
    {
        $this->authorize('terminate sessions');

        DB::table('sessions')->where('id', $id)->delete();

        return back()->with('success', 'Session terminated successfully.');
    }
}
