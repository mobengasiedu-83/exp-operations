<?php

namespace App\Http\Controllers;

use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reporting;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('view reports'), 403);
        $user = $request->user();

        $definitions = ReportDefinition::query()
            ->accessibleBy($user)
            ->where('enabled', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'category', 'grain', 'description', 'sensitive', 'kpis']);

        $latest = ReportRun::query()
            ->whereIn('report_definition_id', $definitions->pluck('id'))
            ->orderByDesc('generated_at')
            ->get()
            ->unique('report_definition_id')
            ->keyBy('report_definition_id');

        return Inertia::render('Reports/Index', [
            'definitions' => $definitions->map(fn (ReportDefinition $definition): array => [
                ...$definition->only(['id', 'key', 'name', 'category', 'grain', 'description', 'sensitive']),
                'kpi_count' => count($definition->kpis),
                'latest_run' => ($run = $latest->get($definition->id)) === null ? null : [
                    'reference' => $run->reference,
                    'period_start' => $run->period_start->toDateString(),
                    'period_end' => $run->period_end->toDateString(),
                    'generated_at' => $run->generated_at->toIso8601String(),
                    'revision' => $run->revision,
                ],
            ])->values(),
            'period' => [
                'today' => CarbonImmutable::today()->toDateString(),
                'max_days' => (int) config('reporting.max_window_days', 92),
            ],
        ]);
    }

    public function show(Request $request, ReportDefinition $report, Reporting $reporting): Response
    {
        $user = $this->authorised($request, $report);
        [$from, $to] = $this->window($request);

        return Inertia::render('Reports/Show', [
            'definition' => $report->only(['id', 'key', 'name', 'category', 'grain', 'description', 'sensitive', 'revision']),
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'max_days' => (int) config('reporting.max_window_days', 92),
                'timezone' => config('app.timezone'),
            ],
            'kpis' => $reporting->compute($report->kpis, $from, $to),
            'runs' => $report->runs()
                ->with('generator:id,name')
                ->orderByDesc('generated_at')
                ->limit(8)
                ->get()
                ->map(fn (ReportRun $run): array => [
                    'id' => $run->id,
                    'reference' => $run->reference,
                    'period_start' => $run->period_start->toDateString(),
                    'period_end' => $run->period_end->toDateString(),
                    'generated_at' => $run->generated_at->toIso8601String(),
                    'generated_by' => $run->generator?->name,
                    'kpi_count' => $run->kpi_count,
                    'revision' => $run->revision,
                    'checksum' => $run->checksum,
                ]),
            'canExport' => $user->can('export reports'),
        ]);
    }

    public function store(Request $request, ReportDefinition $report, Reporting $reporting): RedirectResponse
    {
        $user = $this->authorised($request, $report);
        [$from, $to] = $this->window($request);
        $kpis = $reporting->compute($report->kpis, $from, $to);
        $totals = collect($kpis)->mapWithKeys(fn (array $row): array => [$row['key'] => $row['value']])->all();
        $checksum = hash('sha256', json_encode($kpis, JSON_THROW_ON_ERROR));
        $generatedAt = CarbonImmutable::now();

        DB::transaction(function () use ($report, $from, $to, $kpis, $totals, $checksum, $generatedAt, $user): void {
            $run = ReportRun::query()
                ->lockForUpdate()
                ->where('report_definition_id', $report->id)
                ->whereDate('period_start', $from->toDateString())
                ->whereDate('period_end', $to->toDateString())
                ->where('source', 'manual')
                ->first();

            $attributes = [
                'generated_by' => $user->id,
                'generated_at' => $generatedAt,
                'kpi_count' => count($kpis),
                'breakdown_count' => 0,
                'totals' => $totals,
                'governance' => [
                    'timezone' => config('app.timezone'),
                    'definition_revision' => $report->revision,
                    'requested_keys' => count($report->kpis),
                    'computed_keys' => count($kpis),
                ],
                'checksum' => $checksum,
                'revision' => $run === null ? 1 : $run->revision + 1,
            ];

            if ($run === null) {
                $run = $report->runs()->create([
                    ...$attributes,
                    'reference' => 'RPT-'.$from->format('Ymd').'-'.$to->format('Ymd').'-'.Str::upper(Str::random(6)),
                    'period_start' => $from->toDateString(),
                    'period_end' => $to->toDateString(),
                    'source' => 'manual',
                    'status' => 'completed',
                ]);
            } else {
                $run->update($attributes);
                $run->snapshots()->delete();
            }

            $run->snapshots()->createMany(array_map(fn (array $row): array => [
                'key' => $row['key'],
                'category' => $row['category'],
                'unit' => $row['unit'],
                'value' => $row['value'],
                'numerator' => $row['numerator'],
                'denominator' => $row['denominator'],
                'exclusions' => $row['exclusions'],
                'definition' => $row['definition'],
                'computed_at' => $generatedAt,
            ], $kpis));

            activity('reporting')->causedBy($user)->performedOn($run)
                ->withProperties([
                    'definition' => $report->key,
                    'period' => [$from->toDateString(), $to->toDateString()],
                    'kpis' => count($kpis),
                    'checksum' => $checksum,
                ])
                ->log('Report snapshot saved');
        });

        return to_route('reports.show', [
            'report' => $report->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ])->with('success', 'Report snapshot saved with its KPI evidence.');
    }

    public function export(Request $request, ReportDefinition $report, Reporting $reporting): StreamedResponse
    {
        $user = $this->authorised($request, $report);
        abort_unless($user->can('export reports'), 403);
        [$from, $to] = $this->window($request);
        $kpis = $reporting->compute($report->kpis, $from, $to);

        activity('reporting')->causedBy($user)->performedOn($report)
            ->withProperties([
                'period' => [$from->toDateString(), $to->toDateString()],
                'format' => 'csv',
                'kpis' => count($kpis),
            ])
            ->log('Report exported');

        return response()->streamDownload(function () use ($report, $kpis, $from, $to): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, [
                'Report', 'Period start', 'Period end', 'KPI key', 'KPI', 'Category', 'Unit',
                'Value', 'Numerator', 'Denominator', 'Definition', 'Exclusions',
            ]);

            foreach ($kpis as $row) {
                fputcsv($output, [
                    $report->name, $from->toDateString(), $to->toDateString(), $row['key'], $row['label'],
                    $row['category'], $row['unit'], $row['value'] ?? '', $row['numerator'] ?? '',
                    $row['denominator'] ?? '', $row['definition'], implode('; ', $row['exclusions']),
                ]);
            }

            fclose($output);
        }, 'report-'.$report->key.'-'.$from->toDateString().'-to-'.$to->toDateString().'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Apply the report permission, sensitivity and enabled-state rules.
     */
    private function authorised(Request $request, ReportDefinition $report): User
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can('view reports'), 403);
        abort_unless($report->enabled, 404);
        abort_unless($user->can($report->permission), 403);

        return $user;
    }

    /**
     * Resolve the requested period, defaulting to today, and bound its length.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->startOfDay()
            : CarbonImmutable::today()->startOfDay();
        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])->endOfDay()
            : CarbonImmutable::today()->endOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'The period end cannot be before the period start.']);
        }

        $maxDays = (int) config('reporting.max_window_days', 92);

        if ($from->diffInDays($to) > $maxDays) {
            throw ValidationException::withMessages([
                'to' => "A manual report covers at most {$maxDays} days. Narrow the period or schedule the report.",
            ]);
        }

        return [$from, $to];
    }
}
