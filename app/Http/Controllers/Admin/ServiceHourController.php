<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceHour;
use App\Services\ServiceCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceHourController extends Controller
{
    public function index(ServiceCalendar $calendar): Response
    {
        $this->authorize('view settings');

        return Inertia::render('Admin/ServiceHours/Index', [
            'hours' => ServiceHour::orderBy('name')->paginate(25),
            'service' => $calendar->state(),
            'holidays' => $calendar->upcomingHolidays(6),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('edit settings');
        ServiceHour::create($this->validated($request));

        return back();
    }

    public function update(Request $request, ServiceHour $serviceHour): RedirectResponse
    {
        $this->authorize('edit settings');
        $serviceHour->update($this->validated($request));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'opens_at' => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'],
            'days' => ['nullable', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'is_closed' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var list<int> $days */
        $days = array_values(array_unique(array_map('intval', (array) ($data['days'] ?? []))));
        $data['days'] = $days === [] ? null : $days;

        return $data;
    }
}
