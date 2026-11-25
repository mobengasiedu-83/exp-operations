<?php

namespace App\Http\Controllers;

use App\Models\Dentist;
use App\Models\DentistAvailability;
use App\Models\DentistCredential;
use App\Models\DentistNumber;
use App\Models\DentistRoster;
use App\Models\User;
use App\Services\DentistDirectory;
use App\Services\GhanaPhoneNumber;
use App\Services\RosterPlanner;
use App\Services\ServiceCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DentistController extends Controller
{
    /** Unmatched destinations are derived per request, so they page in fixed-size slices. */
    private const UNMATCHED_PER_PAGE = 10;

    public function index(Request $request, DentistDirectory $directory): Response
    {
        $this->authorize('view dentists');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', Rule::in(DentistDirectory::COVERAGE_STATES)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $coverage = $directory->coverage();
        $search = Str::lower(trim((string) ($filters['q'] ?? '')));

        $dentists = collect($coverage['dentists'])
            ->when($search !== '', fn ($rows) => $rows->filter(function (array $row) use ($search): bool {
                if (str_contains(Str::lower((string) $row['name']), $search)) {
                    return true;
                }

                foreach (is_array($row['numbers'] ?? null) ? $row['numbers'] : [] as $number) {
                    if (is_array($number) && str_contains((string) ($number['number'] ?? ''), $search)) {
                        return true;
                    }
                }

                return false;
            }))
            ->when($filters['state'] ?? null, fn ($rows, string $state) => $rows->where('coverage_state', $state))
            ->when($filters['status'] ?? null, fn ($rows, string $status) => $rows->where('active', $status === 'active'))
            ->values()->all();

        return Inertia::render('Dentists/Index', [
            'dentists' => $dentists,
            'summary' => $coverage['summary'],
            'unmatched' => $this->paginateUnmatched($coverage['unmatched'], $request),
            'activityWindow' => $coverage['activity_window'],
            'asOf' => $coverage['as_of'],
            'filters' => [
                'q' => (string) ($filters['q'] ?? ''),
                'state' => (string) ($filters['state'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
            ],
            'canManage' => $this->user($request)->can('edit dentists'),
        ]);
    }

    /**
     * Transfer-selection payload: active dentists with approved numbers only.
     */
    public function selectable(DentistDirectory $directory): JsonResponse
    {
        $this->authorize('view dentists');

        return response()->json($directory->selectable());
    }

    public function numbers(Request $request, DentistDirectory $directory): Response
    {
        $this->authorize('view dentists');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(DentistNumber::STATUSES)],
            'dentist_id' => ['nullable', 'integer', 'exists:dentists,id'],
            'q' => ['nullable', 'string', 'max:40'],
        ]);
        $activity = $directory->activity();

        return Inertia::render('Dentists/Numbers', [
            'numbers' => DentistNumber::query()->with('dentist:id,name')
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->when($filters['dentist_id'] ?? null, fn ($query, int $dentistId) => $query->where('dentist_id', $dentistId))
                ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where(
                    fn ($search) => $search->where('number', 'like', '%'.$term.'%')->orWhere('original', 'like', '%'.$term.'%')
                ))
                ->orderBy('number')->paginate(25)->withQueryString(),
            'dentists' => Dentist::query()->orderBy('name')->get(['id', 'name']),
            'unmatched' => $this->paginateUnmatched($activity['unmatched'], $request),
            'activityWindow' => $activity['window'],
            'counts' => [
                'approved' => DentistNumber::query()->where('status', 'approved')->count(),
                'pending' => DentistNumber::query()->where('status', 'pending')->count(),
                'retired' => DentistNumber::query()->where('status', 'retired')->count(),
            ],
            'filters' => [
                'status' => (string) ($filters['status'] ?? ''),
                'dentist_id' => (string) ($filters['dentist_id'] ?? ''),
                'q' => (string) ($filters['q'] ?? ''),
            ],
            'canManage' => $this->user($request)->can('edit dentists'),
        ]);
    }

    public function credentials(Request $request): Response
    {
        $this->authorize('view dentists');
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['valid', 'expiring', 'expired', 'no_expiry'])],
            'dentist_id' => ['nullable', 'integer', 'exists:dentists,id'],
        ]);
        $today = CarbonImmutable::now();
        $expiringBy = $today->addDays(DentistCredential::EXPIRING_WINDOW_DAYS);

        return Inertia::render('Dentists/Credentials', [
            'credentials' => DentistCredential::query()->with('dentist:id,name')
                ->when($filters['dentist_id'] ?? null, fn ($query, int $dentistId) => $query->where('dentist_id', $dentistId))
                ->when(($filters['state'] ?? null) === 'expired', fn ($query) => $query->expired($today))
                ->when(($filters['state'] ?? null) === 'expiring', fn ($query) => $query->expiringBefore($expiringBy))
                ->when(($filters['state'] ?? null) === 'valid', fn ($query) => $query->whereNotNull('expires_on')->where('expires_on', '>', $expiringBy->toDateString()))
                ->when(($filters['state'] ?? null) === 'no_expiry', fn ($query) => $query->whereNull('expires_on'))
                ->orderByRaw('expires_on IS NULL, expires_on')->orderBy('id')
                ->paginate(25)->withQueryString(),
            'dentists' => Dentist::query()->orderBy('name')->get(['id', 'name']),
            'counts' => [
                'expired' => DentistCredential::query()->expired($today)->count(),
                'expiring' => DentistCredential::query()->expiringBefore($expiringBy)->count(),
                'total' => DentistCredential::query()->count(),
            ],
            'expiringWindowDays' => DentistCredential::EXPIRING_WINDOW_DAYS,
            'filters' => [
                'state' => (string) ($filters['state'] ?? ''),
                'dentist_id' => (string) ($filters['dentist_id'] ?? ''),
            ],
            'canManage' => $this->user($request)->can('edit dentists'),
        ]);
    }

    public function rosters(Request $request, ServiceCalendar $calendar, RosterPlanner $planner): Response
    {
        $this->authorize('view dentists');
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(DentistRoster::KINDS)],
            'dentist_id' => ['nullable', 'integer', 'exists:dentists,id'],
            'window' => ['nullable', Rule::in(['current', 'upcoming', 'past'])],
        ]);
        $now = CarbonImmutable::now();

        return Inertia::render('Dentists/Rosters', [
            'rosters' => DentistRoster::query()->with(['dentist:id,name', 'creator:id,name'])
                ->when($filters['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
                ->when($filters['dentist_id'] ?? null, fn ($query, int $dentistId) => $query->where('dentist_id', $dentistId))
                ->when(($filters['window'] ?? null) === 'current', fn ($query) => $query->where('starts_at', '<=', $now)->where('ends_at', '>', $now))
                ->when(($filters['window'] ?? null) === 'upcoming', fn ($query) => $query->where('starts_at', '>', $now))
                ->when(($filters['window'] ?? null) === 'past', fn ($query) => $query->where('ends_at', '<=', $now))
                ->orderByDesc('starts_at')->orderByDesc('id')
                ->paginate(25)->withQueryString(),
            'dentists' => Dentist::query()->orderBy('name')->get(['id', 'name']),
            'counts' => [
                'current' => DentistRoster::query()->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->count(),
                'upcoming' => DentistRoster::query()->where('starts_at', '>', $now)->count(),
                'leave' => DentistRoster::query()->where('kind', 'leave')->where('ends_at', '>', $now)->count(),
            ],
            'kinds' => DentistRoster::KINDS,
            'filters' => [
                'kind' => (string) ($filters['kind'] ?? ''),
                'dentist_id' => (string) ($filters['dentist_id'] ?? ''),
                'window' => (string) ($filters['window'] ?? ''),
            ],
            'service' => $calendar->state(),
            'holidays' => $calendar->upcomingHolidays(8),
            'plannerPreview' => $planner->preview($calendar->now(), 2),
            'canManage' => $this->user($request)->can('edit dentists'),
        ]);
    }

    /**
     * Generate the standard Monday–Friday, 10:00–14:00 rosters, skipping Ghana
     * public holidays. Existing windows are left untouched so regeneration is
     * safe to press twice.
     */
    public function generateRosters(Request $request, RosterPlanner $planner, ServiceCalendar $calendar): RedirectResponse
    {
        $this->authorize('edit dentists');
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'weeks' => ['required', 'integer', 'min:1', 'max:12'],
            'dentist_id' => ['nullable', 'integer', 'exists:dentists,id'],
        ]);

        $dentistIds = [];
        $selected = filled($validated['dentist_id'] ?? null)
            ? collect([(int) $validated['dentist_id']])
            : Dentist::query()->where('active', true)->orderBy('name')->pluck('id');

        foreach ($selected as $dentistId) {
            $dentistIds[] = (int) $dentistId;
        }

        if ($dentistIds === []) {
            return back()->with('error', 'Add an active dentist before generating the standard roster.');
        }

        $windows = $planner->plan($dentistIds, CarbonImmutable::parse((string) $validated['from']), (int) $validated['weeks']);
        $created = 0;

        foreach ($windows as $window) {
            $exists = DentistRoster::query()
                ->covering()
                ->where('dentist_id', $window['dentist_id'])
                ->where('starts_at', '<', $window['ends_at'])
                ->where('ends_at', '>', $window['starts_at'])
                ->exists();

            if ($exists) {
                continue;
            }

            DentistRoster::create([
                'dentist_id' => $window['dentist_id'],
                'kind' => $window['kind'],
                'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'],
                'notes' => $window['notes'],
                'created_by' => $this->user($request)->id,
            ]);
            $created++;
        }

        activity('dentists')->causedBy($this->user($request))
            ->withProperties([
                'dentists' => count($dentistIds),
                'weeks' => (int) $validated['weeks'],
                'from' => (string) $validated['from'],
                'created' => $created,
                'service_window' => $calendar->opensAt().'–'.$calendar->closesAt(),
            ])->log('Standard roster generated');

        return back()->with('success', $created === 0
            ? 'The standard roster was already in place for that period.'
            : $created.' standard shift windows added across '.count($dentistIds).' dentists.');
    }

    public function availability(Request $request, DentistDirectory $directory): Response
    {
        $this->authorize('view dentists');
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(DentistDirectory::COVERAGE_STATES)],
            'dentist_id' => ['nullable', 'integer', 'exists:dentists,id'],
        ]);
        $now = CarbonImmutable::now();
        $coverage = $directory->coverage($now);

        $dentists = collect($coverage['dentists'])
            ->when($filters['state'] ?? null, fn ($rows, string $state) => $rows->where('coverage_state', $state))
            ->when($filters['dentist_id'] ?? null, fn ($rows, int|string $dentistId) => $rows->where('id', (int) $dentistId))
            ->values()->all();

        return Inertia::render('Dentists/Availability', [
            'dentists' => $dentists,
            'summary' => $coverage['summary'],
            'asOf' => $coverage['as_of'],
            'overrides' => DentistAvailability::query()->with(['dentist:id,name', 'recorder:id,name'])
                ->where('ends_at', '>=', $now->subDay())
                ->when($filters['dentist_id'] ?? null, fn ($query, int $dentistId) => $query->where('dentist_id', $dentistId))
                ->orderByDesc('starts_at')->orderByDesc('id')
                ->paginate(25)->withQueryString(),
            'allDentists' => Dentist::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => DentistAvailability::STATUSES,
            'filters' => [
                'state' => (string) ($filters['state'] ?? ''),
                'dentist_id' => (string) ($filters['dentist_id'] ?? ''),
            ],
            'canManage' => $this->user($request)->can('edit dentists'),
        ]);
    }

    public function performance(Request $request, DentistDirectory $directory): Response
    {
        $this->authorize('view dentists');
        $validated = $request->validate(['days' => ['nullable', 'integer', 'between:1,365']]);
        $defaultDays = (int) config('services.exp_pbx.dentist_performance_window_days', 30);
        $days = (int) ($validated['days'] ?? $defaultDays);

        return Inertia::render('Dentists/Performance', [
            'performance' => $directory->performance($days),
            'filters' => ['days' => $days],
            'canManage' => $this->user($request)->can('edit dentists'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('edit dentists');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        Dentist::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'active' => $validated['active'] ?? true,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Dentist added to the directory.');
    }

    public function update(Request $request, Dentist $dentist): RedirectResponse
    {
        $this->authorize('edit dentists');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $dentist->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'active' => $validated['active'] ?? $dentist->active,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Dentist updated.');
    }

    public function destroy(Dentist $dentist): RedirectResponse
    {
        $this->authorize('edit dentists');
        $dentist->update(['active' => false]);
        $dentist->numbers()->update(['is_primary' => false]);

        return back()->with('success', 'Dentist deactivated and removed from transfer selection.');
    }

    public function storeNumber(Request $request, Dentist $dentist): RedirectResponse
    {
        $this->authorize('edit dentists');
        $validated = $request->validate([
            'number' => ['required', 'string', 'max:40'],
            'label' => ['nullable', 'string', 'max:50'],
            'is_primary' => ['sometimes', 'boolean'],
            'status' => ['nullable', Rule::in(DentistNumber::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $number = $this->normalizedNumber($validated['number']);
        $status = $validated['status'] ?? 'approved';
        $isPrimary = $status === 'approved' && (bool) ($validated['is_primary'] ?? false);

        if ($isPrimary) {
            $this->clearPrimary($dentist);
        }

        $dentist->numbers()->create([
            'number' => $number,
            'original' => $validated['number'],
            'label' => $validated['label'] ?? 'Mobile',
            'is_primary' => $isPrimary,
            'status' => $status,
            'verified_at' => $status === 'approved' ? now() : null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Dentist number saved.');
    }

    public function updateNumber(Request $request, Dentist $dentist, DentistNumber $number): RedirectResponse
    {
        $this->authorize('edit dentists');
        abort_unless($number->dentist_id === $dentist->id, 404);

        $validated = $request->validate([
            'number' => ['required', 'string', 'max:40'],
            'label' => ['nullable', 'string', 'max:50'],
            'is_primary' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(DentistNumber::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $normalizedNumber = $this->normalizedNumber($validated['number'], $number);
        $isPrimary = $validated['status'] === 'approved' && (bool) ($validated['is_primary'] ?? false);

        if ($isPrimary) {
            $this->clearPrimary($dentist, $number);
        }

        $number->update([
            'number' => $normalizedNumber,
            'original' => $validated['number'],
            'label' => $validated['label'] ?? $number->label,
            'is_primary' => $isPrimary,
            'status' => $validated['status'],
            'verified_at' => $validated['status'] === 'approved' ? ($number->verified_at ?? now()) : null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Dentist number updated.');
    }

    public function destroyNumber(Dentist $dentist, DentistNumber $number): RedirectResponse
    {
        $this->authorize('edit dentists');
        abort_unless($number->dentist_id === $dentist->id, 404);

        $number->update(['status' => 'retired', 'is_primary' => false]);

        return back()->with('success', 'Dentist number retired.');
    }

    public function storeCredential(Request $request, Dentist $dentist): RedirectResponse
    {
        $this->authorize('edit dentists');
        $dentist->credentials()->create($this->credentialData($request, $dentist));

        return back()->with('success', 'Credential recorded.');
    }

    public function updateCredential(Request $request, Dentist $dentist, DentistCredential $credential): RedirectResponse
    {
        $this->authorize('edit dentists');
        abort_unless($credential->dentist_id === $dentist->id, 404);

        $credential->update($this->credentialData($request, $dentist, $credential));

        return back()->with('success', 'Credential updated.');
    }

    public function destroyCredential(Dentist $dentist, DentistCredential $credential): RedirectResponse
    {
        $this->authorize('edit dentists');
        abort_unless($credential->dentist_id === $dentist->id, 404);

        $credential->delete();

        return back()->with('success', 'Credential removed.');
    }

    public function storeRoster(Request $request, Dentist $dentist): RedirectResponse
    {
        $this->authorize('edit dentists');
        $data = $this->rosterData($request);
        $this->assertNoDutyOverlap($dentist, $data['kind'], $data['starts_at'], $data['ends_at']);

        $dentist->rosters()->create([
            'kind' => $data['kind'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'notes' => $data['notes'],
            'created_by' => $this->user($request)->id,
        ]);

        return back()->with('success', 'Roster window added.');
    }

    public function updateRoster(Request $request, Dentist $dentist, DentistRoster $roster): RedirectResponse
    {
        $this->authorize('edit dentists');
        abort_unless($roster->dentist_id === $dentist->id, 404);

        $data = $this->rosterData($request);
        $this->assertNoDutyOverlap($dentist, $data['kind'], $data['starts_at'], $data['ends_at'], $roster);

        $roster->update([
            'kind' => $data['kind'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'notes' => $data['notes'],
        ]);

        return back()->with('success', 'Roster window updated.');
    }

    public function destroyRoster(Dentist $dentist, DentistRoster $roster): RedirectResponse
    {
        $this->authorize('edit dentists');
        abort_unless($roster->dentist_id === $dentist->id, 404);

        $roster->delete();

        return back()->with('success', 'Roster window removed.');
    }

    public function storeAvailability(Request $request, Dentist $dentist): RedirectResponse
    {
        $this->authorize('edit dentists');
        $validated = $request->validate([
            'status' => ['required', Rule::in(DentistAvailability::STATUSES)],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $startsAt = CarbonImmutable::parse($validated['starts_at']);
        $endsAt = CarbonImmutable::parse($validated['ends_at']);

        $overlaps = DentistAvailability::query()
            ->where('dentist_id', $dentist->id)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'starts_at' => 'This dentist already has an availability override in that window.',
            ]);
        }

        $dentist->availability()->create([
            'status' => $validated['status'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $validated['reason'] ?? null,
            'recorded_by' => $this->user($request)->id,
        ]);

        return back()->with('success', 'Availability override recorded.');
    }

    public function destroyAvailability(Dentist $dentist, DentistAvailability $override): RedirectResponse
    {
        $this->authorize('edit dentists');
        abort_unless($override->dentist_id === $dentist->id, 404);

        if ($override->starts_at->isFuture()) {
            $override->delete();
        } elseif ($override->ends_at->isFuture()) {
            $override->update(['ends_at' => now()]);
        }

        return back()->with('success', 'Availability override ended.');
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialData(Request $request, Dentist $dentist, ?DentistCredential $ignore = null): array
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(DentistCredential::TYPES)],
            'number' => ['required', 'string', 'max:100'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('issued_on'), 'after_or_equal:issued_on')],
            'verified' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $duplicate = DentistCredential::query()
            ->where('dentist_id', $dentist->id)
            ->where('type', $validated['type'])
            ->where('number', $validated['number'])
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'number' => 'This credential is already recorded for the dentist.',
            ]);
        }

        return [
            'type' => $validated['type'],
            'number' => $validated['number'],
            'issuer' => $validated['issuer'] ?? null,
            'issued_on' => $validated['issued_on'] ?? null,
            'expires_on' => $validated['expires_on'] ?? null,
            'verified_at' => ($validated['verified'] ?? false) ? ($ignore->verified_at ?? now()) : null,
            'notes' => $validated['notes'] ?? null,
        ];
    }

    /**
     * @return array{kind: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, notes: string|null}
     */
    private function rosterData(Request $request): array
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(DentistRoster::KINDS)],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return [
            'kind' => (string) $validated['kind'],
            'starts_at' => CarbonImmutable::parse((string) $validated['starts_at']),
            'ends_at' => CarbonImmutable::parse((string) $validated['ends_at']),
            'notes' => $validated['notes'] ?? null,
        ];
    }

    private function assertNoDutyOverlap(Dentist $dentist, string $kind, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?DentistRoster $ignore = null): void
    {
        if (! in_array($kind, DentistRoster::COVERING_KINDS, true)) {
            return;
        }

        $overlaps = DentistRoster::query()->covering()
            ->where('dentist_id', $dentist->id)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->id))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'starts_at' => 'This dentist already has an overlapping duty window. Adjust or end the existing shift first.',
            ]);
        }
    }

    private function normalizedNumber(string $raw, ?DentistNumber $ignore = null): string
    {
        $number = GhanaPhoneNumber::normalize($raw);

        if ($number === null) {
            throw ValidationException::withMessages([
                'number' => 'Enter a valid Ghana mobile number, for example 024 123 4567.',
            ]);
        }

        $exists = DentistNumber::query()
            ->where('number', $number)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'number' => 'This number is already registered in the dentist directory.',
            ]);
        }

        return $number;
    }

    private function clearPrimary(Dentist $dentist, ?DentistNumber $ignore = null): void
    {
        $dentist->numbers()
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->id))
            ->update(['is_primary' => false]);
    }

    /**
     * Page the derived unmatched destinations without disturbing the directory tables.
     *
     * The rows come from recent mobile legs rather than an Eloquent query, so they
     * carry their own `unmatched_page` parameter; the numbers table keeps `page`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateUnmatched(array $rows, Request $request): LengthAwarePaginator
    {
        $pageName = 'unmatched_page';
        $page = max(1, $request->integer($pageName, 1));

        return (new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * self::UNMATCHED_PER_PAGE, self::UNMATCHED_PER_PAGE),
            count($rows),
            self::UNMATCHED_PER_PAGE,
            $page,
            ['path' => $request->url(), 'pageName' => $pageName],
        ))->withQueryString();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
