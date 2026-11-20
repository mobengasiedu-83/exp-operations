<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\InboundCallDirectory;
use App\Services\IvrJourney;
use App\Services\ServiceCalendar;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every call that arrived on an assigned DID, with the journey the caller took:
 * menu option pressed, whether an agent was requested and how the call ended.
 */
class DidCallController extends Controller
{
    public function index(Request $request, InboundCallDirectory $directory, IvrJourney $journey, ServiceCalendar $calendar): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canAny(['view call history', 'view pbx']), 403);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'option' => ['nullable', 'string', 'max:8'],
            'agent' => ['nullable', Rule::in(['yes', 'no'])],
            'search' => ['nullable', 'string', 'max:40'],
        ]);

        $filters = [
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
            'option' => (string) ($validated['option'] ?? ''),
            'agent' => (string) ($validated['agent'] ?? ''),
            'search' => (string) ($validated['search'] ?? ''),
        ];

        return Inertia::render('Calls/Did', [
            'calls' => $directory->didCalls($filters, $user),
            'filters' => $filters,
            'stats' => $directory->stats($user),
            'service' => $calendar->state(),
            'menu' => $journey->options(),
            'agentOption' => $journey->agentOption(),
            'inboundDids' => config('services.exp_pbx.inbound_dids', []),
            'today' => $directory->today()->toIso8601String(),
        ]);
    }
}
