<?php

namespace App\Http\Controllers;

use App\Models\CorrelationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CorrelationExceptionController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canAny(['view pbx', 'view call history']), 403);
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(['ingestion', 'projection', 'reconciliation'])],
            'code' => ['nullable', 'string', 'max:100'],
            'connection_id' => ['nullable', 'integer', 'exists:pbx_connections,id'],
        ]);

        return Inertia::render('Calls/Exceptions', [
            'exceptions' => CorrelationException::query()->with('event:id,event_name,occurred_at,data')
                ->when($filters['category'] ?? null, fn ($query, $value) => $query->where('category', $value))
                ->when($filters['code'] ?? null, fn ($query, $value) => $query->where('code', $value))
                ->when($filters['connection_id'] ?? null, fn ($query, $value) => $query->where('pbx_connection_id', $value))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
            'filters' => $filters,
        ]);
    }
}
