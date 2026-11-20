<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AmiEvent;
use Illuminate\Http\Request;
use Inertia\Response;

class IntegrationLogController extends Controller
{
    /**
     * Display integration logs (raw AMI events).
     */
    public function index(Request $request): Response
    {
        $this->authorize('view pbx');

        $validated = $request->validate([
            'event' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'to' => ['nullable', 'date_format:Y-m-d\\TH:i', 'after_or_equal:from'],
        ]);

        $query = AmiEvent::query()
            ->with('connection')
            ->when($validated['event'] ?? null, fn ($q, $event) => $q->where('event_name', $event))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('received_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('received_at', '<=', $to))
            ->latest('received_at');

        $events = $query->paginate(50)->withQueryString();

        return inertia('Admin/IntegrationLogs/Index', [
            'events' => $events,
            'filters' => $validated,
        ]);
    }
}
