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
        $filters = $this->filters($request);

        return Inertia::render('CallTime/Overview', [
            'overview' => $analytics->overview($this->window($analytics, $filters)),
            'filters' => $filters,
            'windows' => self::WINDOWS,
        ]);
    }

    public function agents(Request $request, CallTimeAnalytics $analytics): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('CallTime/Agents', [
            'agents' => $analytics->agents($this->window($analytics, $filters)),
            'filters' => $filters,
            'windows' => self::WINDOWS,
        ]);
    }

    public function dentists(Request $request, CallTimeAnalytics $analytics): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('CallTime/Dentists', [
            'dentists' => $analytics->dentists($this->window($analytics, $filters)),
            'filters' => $filters,
            'windows' => self::WINDOWS,
        ]);
    }

    /**
     * Validate the window and gate the whole menu behind `view call time`.
     *
     * `days` is the rolling window the page offers. `from`/`to` are inclusive
     * calendar days (a single day, a month, a quarter); either end may be sent
     * on its own, and a supplied range takes precedence over the day count.
     *
     * @return array{days: int, from: string|null, to: string|null}
     */
    private function filters(Request $request): array
    {
        $this->authorize('view call time');

        $validated = $request->validate([
            'days' => ['nullable', 'integer', Rule::in(self::WINDOWS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            'days' => (int) ($validated['days'] ?? CallTimeAnalytics::DEFAULT_DAYS),
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    /**
     * Resolve the filters into the window {@see CallTimeAnalytics::window()}
     * expects.
     *
     * @param  array{days: int, from: string|null, to: string|null}  $filters
     * @return array<string, mixed>
     */
    private function window(CallTimeAnalytics $analytics, array $filters): array
    {
        return $analytics->window($filters['days'], $filters['from'], $filters['to']);
    }
}
