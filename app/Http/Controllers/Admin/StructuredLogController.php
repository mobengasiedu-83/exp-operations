<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StructuredListenerLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StructuredLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('view pbx');
        $filters = $request->validate([
            'level' => ['nullable', Rule::in(['info', 'warning', 'error'])],
            'connection_id' => ['nullable', 'integer', 'exists:pbx_connections,id'],
        ]);

        return Inertia::render('Admin/StructuredLogs/Index', [
            'logs' => StructuredListenerLog::query()
                ->when($filters['level'] ?? null, fn ($query, $level) => $query->where('level', $level))
                ->when($filters['connection_id'] ?? null, fn ($query, $id) => $query->where('connection_id', $id))
                ->latest('id')->paginate(50)->withQueryString(),
        ]);
    }
}
