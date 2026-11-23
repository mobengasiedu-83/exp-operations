<?php

namespace App\Http\Controllers;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\DashboardPreference;
use App\Models\User;
use App\Services\AlertEvaluator;
use App\Services\LiveOperationsSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('view pbx');
        $user = $this->user($request);
        $preference = DashboardPreference::query()->where('user_id', $user->id)->first();

        return Inertia::render('Exp/Dashboard', [
            'refreshSeconds' => $this->refreshSeconds($user, $preference),
            'refreshBounds' => ['min' => LiveOperationsSnapshot::MIN_REFRESH_SECONDS, 'max' => LiveOperationsSnapshot::MAX_REFRESH_SECONDS],
            'filters' => array_merge(['from' => '', 'to' => '', 'agent' => '', 'destination' => '', 'state' => ''], (array) ($preference->filters ?? [])),
            'canAcknowledge' => $user->can('edit settings'),
        ]);
    }

    public function live(Request $request, LiveOperationsSnapshot $snapshot, AlertEvaluator $evaluator): JsonResponse
    {
        // The Operations overview on the dashboard reads this endpoint, so every
        // role that can see the overview may read the snapshot. Opening the
        // console pages (/exp, active calls, transfers, alerts) still needs
        // `view pbx`; the payload already masks phone numbers.
        $user = $request->user();
        abort_unless($user instanceof User && $user->canAny(['view pbx', 'view operations overview']), 403);
        $filters = $this->validatedFilters($request);
        $this->evaluateAlerts($evaluator);

        return response()->json($snapshot->build($filters));
    }

    public function preferences(Request $request): RedirectResponse
    {
        $this->authorize('view pbx');
        $user = $this->user($request);

        $validated = $request->validate([
            'filters' => ['sometimes', 'array'],
            'filters.from' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'filters.to' => ['nullable', 'date_format:Y-m-d\TH:i', 'after_or_equal:filters.from'],
            'filters.agent' => ['nullable', 'regex:/^[0-9]{1,8}$/'],
            'filters.destination' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.\-\/\[\]]+$/'],
            'filters.state' => ['nullable', Rule::in(['active', 'completed', 'abandoned'])],
            'refresh_seconds' => ['required', 'integer', 'between:'.LiveOperationsSnapshot::MIN_REFRESH_SECONDS.','.LiveOperationsSnapshot::MAX_REFRESH_SECONDS],
        ]);

        DashboardPreference::query()->updateOrCreate(['user_id' => $user->id], [
            'filters' => $validated['filters'] ?? [],
            'refresh_seconds' => (int) $validated['refresh_seconds'],
        ]);

        return back();
    }

    public function activeCalls(Request $request, LiveOperationsSnapshot $snapshot): Response
    {
        $this->authorize('view pbx');
        $filters = $this->validatedFilters($request);

        return Inertia::render('LiveOperations/ActiveCalls', [
            'snapshot' => $snapshot->build($filters),
            'filters' => $filters,
            'refreshSeconds' => $this->refreshSeconds($this->user($request)),
        ]);
    }

    public function transfers(Request $request, LiveOperationsSnapshot $snapshot): Response
    {
        $this->authorize('view pbx');
        $filters = $this->validatedFilters($request);

        return Inertia::render('LiveOperations/Transfers', [
            'snapshot' => $snapshot->build($filters),
            'filters' => $filters,
            'refreshSeconds' => $this->refreshSeconds($this->user($request)),
        ]);
    }

    public function alerts(Request $request): Response
    {
        $this->authorize('view pbx');
        $user = $this->user($request);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved'])],
            'severity' => ['nullable', Rule::in(['critical', 'warning', 'info'])],
        ]);

        return Inertia::render('LiveOperations/Alerts', [
            'alerts' => AlertEvent::query()
                ->with(['rule:id,key,name,category', 'interaction:id,started_at,status'])
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['severity'] ?? null, fn ($query, $severity) => $query->where('severity', $severity))
                ->orderByDesc('last_seen_at')
                ->paginate(25)->withQueryString(),
            'rules' => AlertRule::query()->orderBy('category')->orderBy('name')
                ->get(['id', 'key', 'name', 'category', 'metric', 'threshold_seconds', 'severity', 'enabled']),
            'counts' => [
                'open' => AlertEvent::query()->where('status', 'open')->count(),
                'acknowledged' => AlertEvent::query()->where('status', 'acknowledged')->count(),
                'resolved_24h' => AlertEvent::query()->where('status', 'resolved')->where('resolved_at', '>=', now()->subDay())->count(),
            ],
            'filters' => ['status' => $filters['status'] ?? '', 'severity' => $filters['severity'] ?? ''],
            'canAcknowledge' => $user->can('edit settings'),
            'refreshSeconds' => $this->refreshSeconds($user),
        ]);
    }

    public function acknowledge(Request $request, AlertEvent $alert): RedirectResponse
    {
        $this->authorize('edit settings');
        $user = $this->user($request);

        if ($alert->status !== 'resolved') {
            $alert->update([
                'status' => 'acknowledged',
                'acknowledged_at' => now(),
                'acknowledged_by' => $user->id,
            ]);
        }

        return back();
    }

    /** @return array<string, string|int|null> */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'to' => ['nullable', 'date_format:Y-m-d\TH:i', 'after_or_equal:from'],
            'agent' => ['nullable', 'regex:/^[0-9]{1,8}$/'],
            'destination' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.\-\/\[\]]+$/'],
            'state' => ['nullable', Rule::in(['active', 'completed', 'abandoned'])],
            'connection_id' => ['nullable', 'integer', 'exists:pbx_connections,id'],
        ]);
    }

    private function refreshSeconds(User $user, ?DashboardPreference $preference = null): int
    {
        $preference ??= DashboardPreference::query()->where('user_id', $user->id)->first();
        $seconds = $preference instanceof DashboardPreference ? $preference->refresh_seconds : (int) config('services.exp_pbx.live_refresh_seconds', 3);

        return max(LiveOperationsSnapshot::MIN_REFRESH_SECONDS, min(LiveOperationsSnapshot::MAX_REFRESH_SECONDS, $seconds));
    }

    private function evaluateAlerts(AlertEvaluator $evaluator): void
    {
        $last = Cache::get('exp:alerts:evaluated-at');

        if (! is_int($last) || $last < now()->subSeconds(15)->getTimestamp()) {
            $evaluator->evaluate();
            Cache::forever('exp:alerts:evaluated-at', now()->getTimestamp());
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
