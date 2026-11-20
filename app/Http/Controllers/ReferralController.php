<?php

namespace App\Http\Controllers;

use App\Models\CallerCase;
use App\Models\Dentist;
use App\Models\Referral;
use App\Models\User;
use App\Services\ReferralRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReferralController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('view referrals');
        $user = $this->user($request);
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['directory', 'actions', 'outcomes'])],
            'route' => ['nullable', Rule::in(Referral::ROUTES)],
        ]);
        $view = $filters['view'] ?? 'actions';
        $base = Referral::query()->visibleTo($user)
            ->when($filters['route'] ?? null, fn ($query, string $route) => $query->where('route', $route));
        $referrals = match ($view) {
            'outcomes' => (clone $base)->where('status', 'outcome_recorded'),
            'directory' => (clone $base)->open(),
            default => (clone $base)->open(),
        };

        return Inertia::render('Referrals/Index', [
            'referrals' => $referrals->with(['case:id,priority,status', 'dentist:id,name', 'referredBy:id,name'])
                ->orderBy('created_at')->orderBy('id')->paginate(25)->withQueryString()
                ->through(fn (Referral $referral): array => [
                    'id' => $referral->id,
                    'case_id' => $referral->case_id,
                    'case_priority' => $referral->case?->priority,
                    'case_status' => $referral->case?->status,
                    'route' => $referral->route,
                    'status' => $referral->status,
                    'destination_name' => $referral->destination_name,
                    'destination_contact' => $referral->destination_contact,
                    'reason' => $referral->reason,
                    'dentist' => $referral->dentist?->name,
                    'referred_at' => $referral->created_at?->toIso8601String(),
                    'action_reference' => $referral->action_reference,
                    'actioned_at' => $referral->actioned_at?->toIso8601String(),
                    'outcome' => $referral->outcome,
                    'outcome_note' => $referral->outcome_note,
                    'outcome_recorded_at' => $referral->outcome_recorded_at?->toIso8601String(),
                    'follow_up_at' => $referral->follow_up_at?->toIso8601String(),
                    'revision' => $referral->revision,
                ]),
            'destinations' => Referral::query()->visibleTo($user)
                ->selectRaw('destination_name, COUNT(*) as referrals, SUM(CASE WHEN route = ? THEN 1 ELSE 0 END) as urgent, MAX(created_at) as last_referred_at', ['urgent'])
                ->groupBy('destination_name')->orderBy('destination_name')->get()
                ->map(fn (Referral $row): array => [
                    'destination_name' => (string) $row->getAttribute('destination_name'),
                    'referrals' => (int) $row->getAttribute('referrals'),
                    'urgent' => (int) $row->getAttribute('urgent'),
                    'last_referred_at' => $row->getAttribute('last_referred_at'),
                ])->values()->all(),
            'counts' => [
                'actions' => (clone $base)->where('status', 'pending')->count(),
                'outcomes' => (clone $base)->where('status', 'outcome_recorded')->count(),
                'urgent_open' => (clone $base)->open()->where('route', 'urgent')->count(),
            ],
            'filters' => [...$filters, 'view' => $view],
            'cases' => $user->can('edit referrals') ? CallerCase::query()->visibleTo($user)->where('status', '!=', 'closed')
                ->whereIn('priority', ['P2', 'P3', 'S'])->orderByDesc('updated_at')->orderByDesc('id')->limit(50)
                ->get(['id', 'priority', 'status']) : [],
            'dentists' => $user->can('edit referrals') ? Dentist::query()->where('active', true)->orderBy('name')->get(['id', 'name']) : [],
            'routes' => Referral::ROUTES,
            'outcomes' => Referral::OUTCOMES,
            'canEdit' => $user->can('edit referrals'),
        ]);
    }

    public function store(Request $request, ReferralRouter $router): RedirectResponse
    {
        $this->authorize('view referrals');
        $this->authorize('edit referrals');
        $data = $request->validate([
            'case_id' => ['required', 'integer', 'exists:cases,id'],
            'route' => ['required', Rule::in(Referral::ROUTES)],
            'destination_name' => ['required', 'string', 'max:150'],
            'destination_contact' => ['nullable', 'string', 'max:150'],
            'reason' => ['required', 'string', 'max:1000'],
            'dentist_id' => ['nullable', 'integer', 'exists:dentists,id'],
            'follow_up_at' => ['nullable', 'date'],
        ]);
        $router->create(CallerCase::query()->findOrFail((int) $data['case_id']), $this->user($request), $data);

        return back()->with('success', 'Referral recorded. Emergency (P1) cases are never queued here.');
    }

    public function action(Request $request, Referral $referral, ReferralRouter $router): RedirectResponse
    {
        $this->authorize('view referrals');
        $this->authorize('edit referrals');
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'action_reference' => ['required', 'string', 'max:150'],
        ]);
        $router->act($referral, $this->user($request), $data);

        return back()->with('success', 'Referral action recorded.');
    }

    public function outcome(Request $request, Referral $referral, ReferralRouter $router): RedirectResponse
    {
        $this->authorize('view referrals');
        $this->authorize('edit referrals');
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', Rule::in(Referral::OUTCOMES)],
            'outcome_note' => ['required', 'string', 'max:1000'],
        ]);
        $router->recordOutcome($referral, $this->user($request), $data);

        return back()->with('success', 'Referral outcome recorded.');
    }

    public function cancel(Request $request, Referral $referral, ReferralRouter $router): RedirectResponse
    {
        $this->authorize('view referrals');
        $this->authorize('edit referrals');
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'outcome_note' => ['required', 'string', 'max:1000'],
        ]);
        $router->cancel($referral, $this->user($request), $data);

        return back()->with('success', 'Referral cancelled and retained in history.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
