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
 * Calls the agent actually received: the caller pressed the agent option on the
 * IVR tree, or the PBX delivered the caller to the agent leg.
 */
class AgentCallController extends Controller
{
    public function index(Request $request, InboundCallDirectory $directory, IvrJourney $journey, ServiceCalendar $calendar): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canAny(['view call history', 'view cases']), 403);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'basis' => ['nullable', Rule::in(['agent', 'all'])],
            'search' => ['nullable', 'string', 'max:40'],
        ]);

        $filters = [
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
            'basis' => (string) ($validated['basis'] ?? 'agent'),
            'search' => (string) ($validated['search'] ?? ''),
        ];

        return Inertia::render('Calls/Agent', [
            'calls' => $directory->agentCalls($filters, $user),
            'filters' => $filters,
            'stats' => $directory->stats($user),
            'service' => $calendar->state(),
            'agentOption' => $journey->agentOption(),
            'menu' => $journey->options(),
            'today' => $directory->today()->toIso8601String(),
        ]);
    }
}
