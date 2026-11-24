<?php

namespace App\Http\Controllers;

use App\Services\CallTimeAnalytics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Call time menu: how long callers wait, how long agents handle each call
 * and how much connected time each dentist receives.
 *
 * Every screen is read-only and masked; the permission is separate from the
 * live console so a read-only role can study the day's timing without opening
 * PBX telemetry tools or the Administration hub.
 */
class CallTimeController extends Controller
{
    /** Selectable rolling windows, in days. */
    private const WINDOWS = [1, 7, 30, 90];

    public function overview(Request $request, CallTimeAnalytics $analytics): Response
    {
        $days = $this->days($request);

        return Inertia::render('CallTime/Overview', [
            'overview' => $analytics->overview($days),
            'filters' => ['days' => $days],
            'windows' => self::WINDOWS,
        ]);
    }

    public function agents(Request $request, CallTimeAnalytics $analytics): Response
    {
        $days = $this->days($request);

        return Inertia::render('CallTime/Agents', [
            'agents' => $analytics->agents($days),
            'filters' => ['days' => $days],
            'windows' => self::WINDOWS,
        ]);
    }

    public function dentists(Request $request, CallTimeAnalytics $analytics): Response
    {
        $days = $this->days($request);

        return Inertia::render('CallTime/Dentists', [
            'dentists' => $analytics->dentists($days),
            'filters' => ['days' => $days],
            'windows' => self::WINDOWS,
        ]);
    }

    /** Validate the window and gate the whole menu behind `view call time`. */
    private function days(Request $request): int
    {
        $this->authorize('view call time');

        $validated = $request->validate([
            'days' => ['nullable', 'integer', Rule::in(self::WINDOWS)],
        ]);

        return (int) ($validated['days'] ?? CallTimeAnalytics::DEFAULT_DAYS);
    }
}
