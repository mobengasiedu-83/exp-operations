<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CdrRecord;
use App\Models\CelRecord;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

class CdrCelController extends Controller
{
    /**
     * Display CDR and CEL records.
     */
    public function index(Request $request): Response
    {
        $this->authorize('view pbx');

        $validated = $request->validate([
            'type' => ['sometimes', 'string', Rule::in(['cdr', 'cel'])],
            'uniqueid' => ['sometimes', 'string', 'max:255'],
            'linkedid' => ['sometimes', 'string', 'max:255'],
            'from' => ['sometimes', 'date_format:Y-m-d\\TH:i'],
            'to' => ['sometimes', 'date_format:Y-m-d\\TH:i', 'after_or_equal:from'],
        ]);

        $type = $validated['type'] ?? 'cdr';

        if ($type === 'cel') {
            $query = CelRecord::query()
                ->when($validated['uniqueid'] ?? null, fn ($q, $uid) => $q->where('uniqueid', $uid))
                ->when($validated['linkedid'] ?? null, fn ($q, $lid) => $q->where('linkedid', $lid))
                ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('event_time', '>=', $from))
                ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('event_time', '<=', $to))
                ->latest('event_time');

            $records = $query->paginate(50)->withQueryString();
        } else {
            $query = CdrRecord::query()
                ->when($validated['uniqueid'] ?? null, fn ($q, $uid) => $q->where('uniqueid', $uid))
                ->when($validated['linkedid'] ?? null, fn ($q, $lid) => $q->where('linkedid', $lid))
                ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('start', '>=', $from))
                ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('start', '<=', $to))
                ->latest('start');

            $records = $query->paginate(50)->withQueryString();
        }

        return inertia('Admin/CdrCel/Index', [
            'type' => $type,
            'records' => $records,
            'filters' => $validated,
        ]);
    }
}
