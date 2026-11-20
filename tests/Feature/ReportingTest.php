<?php

use App\Models\CallBridge;
use App\Models\CallerCase;
use App\Models\CallLeg;
use App\Models\Interaction;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\TransferAttempt;
use App\Models\User;
use App\Services\Reporting;
use Carbon\CarbonImmutable;
use Database\Seeders\ReportCatalogueSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function reportingUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function reportingCatalogue(): void
{
    (new ReportCatalogueSeeder)->run();
}

function reportingWindow(): array
{
    return [CarbonImmutable::today()->startOfDay(), CarbonImmutable::today()->endOfDay()];
}

test('reports are closed to users without the reporting permission', function () {
    reportingCatalogue();

    $this->withoutVite();

    $this->actingAs(reportingUser(['view cases']))
        ->get(route('reports.index'))
        ->assertForbidden();
});

test('supervisors see operational reports but never the executive summary', function () {
    reportingCatalogue();

    $supervisor = reportingUser(['view reports']);
    $executive = ReportDefinition::query()->where('key', 'executive-summary')->firstOrFail();

    $this->withoutVite();

    $this->actingAs($supervisor)->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/Index')
            ->has('definitions', 9)
            ->where('period.max_days', 92));

    expect(ReportDefinition::query()->accessibleBy($supervisor)->where('sensitive', true)->exists())->toBeFalse();

    $this->actingAs($supervisor)->get(route('reports.show', $executive))->assertForbidden();
});

test('the executive summary unlocks only with the explicit executive grant', function () {
    reportingCatalogue();

    $director = reportingUser(['view reports', 'view executive reports']);
    $executive = ReportDefinition::query()->where('key', 'executive-summary')->firstOrFail();

    $this->withoutVite();

    $this->actingAs($director)->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/Index')
            ->has('definitions', 10));

    $this->actingAs($director)->get(route('reports.show', $executive))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/Show')
            ->has('kpis', 9)
            ->where('definition.key', 'executive-summary')
            ->where('canExport', false));
});

test('published KPI definitions are computed from stored evidence', function () {
    [$from, $to] = reportingWindow();

    $answered = Interaction::factory()->create(['is_exp' => true, 'started_at' => $from->addMinutes(5)]);
    $abandoned = Interaction::factory()->create(['is_exp' => true, 'started_at' => $from->addMinutes(10)]);

    CallLeg::create([
        'interaction_id' => $answered->id, 'pbx_connection_id' => $answered->pbx_connection_id,
        'uniqueid' => 'agent-leg', 'role' => 'agent', 'started_at' => $from->addMinutes(5),
        'answered_at' => $from->addMinutes(5)->addSeconds(12),
    ]);

    CallerCase::factory()->create([
        'interaction_id' => $answered->id, 'priority' => 'P1', 'contact_kind' => 'ultra_short',
    ]);
    CallerCase::factory()->create(['interaction_id' => $abandoned->id, 'priority' => 'P3']);

    TransferAttempt::factory()->create([
        'interaction_id' => $answered->id, 'status' => 'accepted', 'dial_started_at' => $from->addMinutes(6),
        'answered_at' => $from->addMinutes(6)->addSeconds(15), 'human_accepted_at' => $from->addMinutes(6)->addSeconds(30),
        'human_outcome' => 'accepted', 'dial_to_answer_seconds' => 15, 'ringing_seconds' => 40,
        'private_handover_seconds' => 30, 'consultation_seconds' => 120,
    ]);
    TransferAttempt::factory()->create([
        'interaction_id' => $abandoned->id, 'status' => 'transfer_failed', 'dial_started_at' => $from->addMinutes(11),
    ]);

    CallBridge::create([
        'interaction_id' => $answered->id, 'bridge_uniqueid' => 'bridge-1', 'stage' => 'consultation',
        'participants' => ['caller', 'mobile'], 'started_at' => $from->addMinutes(8),
        'ended_at' => $from->addMinutes(13), 'duration_seconds' => 300,
    ]);

    $rows = collect(app(Reporting::class)->compute(
        ['inbound_interactions', 'answered_interactions', 'answer_rate', 'answered_within_30_seconds',
            'abandoned_interactions', 'cases_opened', 'priority_p1', 'priority_p3', 'ultra_short_contacts',
            'transfer_attempts', 'transfer_answer_rate', 'transfer_acceptance_rate', 'transfer_failures',
            'average_dial_to_answer_seconds', 'average_ringing_seconds', 'average_private_handover_seconds',
            'average_consultation_seconds', 'average_verified_bridge_seconds', 'consultation_minutes',
            'cdr_journeys', 'reconciliation_variance'],
        $from,
        $to,
    ))->keyBy('key');

    expect($rows['inbound_interactions']['value'])->toBe(2)
        ->and($rows['answered_interactions']['value'])->toBe(1)
        ->and($rows['answer_rate']['value'])->toBe(50.0)
        ->and($rows['answered_within_30_seconds']['value'])->toBe(100.0)
        ->and($rows['abandoned_interactions']['value'])->toBe(1)
        ->and($rows['cases_opened']['value'])->toBe(2)
        ->and($rows['priority_p1']['value'])->toBe(1)
        ->and($rows['priority_p3']['value'])->toBe(1)
        ->and($rows['ultra_short_contacts']['value'])->toBe(1)
        ->and($rows['transfer_attempts']['value'])->toBe(2)
        ->and($rows['transfer_answer_rate']['value'])->toBe(50.0)
        ->and($rows['transfer_acceptance_rate']['value'])->toBe(50.0)
        ->and($rows['transfer_failures']['value'])->toBe(1)
        ->and($rows['average_dial_to_answer_seconds']['value'])->toBe(15.0)
        ->and($rows['average_ringing_seconds']['value'])->toBe(40.0)
        ->and($rows['average_private_handover_seconds']['value'])->toBe(30.0)
        ->and($rows['average_consultation_seconds']['value'])->toBe(120.0)
        ->and($rows['average_verified_bridge_seconds']['value'])->toBe(300.0)
        ->and($rows['consultation_minutes']['value'])->toBe(2.0)
        ->and($rows['cdr_journeys']['value'])->toBe(0)
        ->and($rows['reconciliation_variance']['value'])->toBe(2)
        ->and($rows['answer_rate']['numerator'])->toBe(1)
        ->and($rows['answer_rate']['denominator'])->toBe(2)
        ->and($rows['answer_rate']['definition'])->toContain('Agent-answered interactions');
});

test('a report with no evidence reports no value instead of zero', function () {
    [$from, $to] = reportingWindow();

    $rows = collect(app(Reporting::class)->compute(['answer_rate', 'qa_average_score'], $from, $to))->keyBy('key');

    expect($rows['answer_rate']['value'])->toBeNull()
        ->and($rows['qa_average_score']['value'])->toBeNull();
});

test('saving a run stores its snapshots and a re-run bumps the revision', function () {
    reportingCatalogue();

    $supervisor = reportingUser(['view reports']);
    $definition = ReportDefinition::query()->where('key', 'daily-operations')->firstOrFail();
    $period = ['from' => CarbonImmutable::today()->toDateString(), 'to' => CarbonImmutable::today()->toDateString()];

    $this->withoutVite();
    $this->actingAs($supervisor)->post(route('reports.store', $definition), $period)
        ->assertRedirect(route('reports.show', ['report' => $definition->id, ...$period]));

    $run = ReportRun::query()->sole();
    $firstSnapshots = $run->snapshots()->count();

    expect($run->revision)->toBe(1)
        ->and($run->kpi_count)->toBe(count($definition->kpis))
        ->and($firstSnapshots)->toBe(count($definition->kpis))
        ->and($run->governance['timezone'])->toBe(config('app.timezone'))
        ->and($run->checksum)->toHaveLength(64)
        ->and($run->status)->toBe('completed');

    $this->actingAs($supervisor)->post(route('reports.store', $definition), $period)->assertRedirect();

    $run->refresh();

    expect(ReportRun::query()->count())->toBe(1)
        ->and($run->revision)->toBe(2)
        ->and($run->snapshots()->count())->toBe($firstSnapshots)
        ->and($run->snapshots()->where('key', 'inbound_interactions')->exists())->toBeTrue();
});

test('an export needs the explicit export grant and is written to the audit trail', function () {
    reportingCatalogue();

    $definition = ReportDefinition::query()->where('key', 'daily-operations')->firstOrFail();

    $this->withoutVite();

    $this->actingAs(reportingUser(['view reports', 'export reports']))
        ->get(route('reports.export', $definition))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $response = $this->actingAs(reportingUser(['view reports', 'export reports']))
        ->get(route('reports.export', $definition));

    expect($response->streamedContent())->toContain('inbound_interactions')
        ->and($response->streamedContent())->toContain('Daily operations');

    expect(Activity::query()->where('log_name', 'reporting')->where('description', 'Report exported')->exists())->toBeTrue();

    $this->actingAs(reportingUser(['view reports']))
        ->get(route('reports.export', $definition))
        ->assertForbidden();
});

test('an inverted or oversized period is refused before any KPI is read', function () {
    reportingCatalogue();

    $supervisor = reportingUser(['view reports']);
    $definition = ReportDefinition::query()->where('key', 'daily-operations')->firstOrFail();

    $this->withoutVite();

    $this->actingAs($supervisor)
        ->get(route('reports.show', ['report' => $definition->id, 'from' => '2026-11-19', 'to' => '2026-11-18']))
        ->assertSessionHasErrors('to');

    $this->actingAs($supervisor)
        ->get(route('reports.show', ['report' => $definition->id, 'from' => '2026-01-01', 'to' => '2026-11-19']))
        ->assertSessionHasErrors('to');

    expect(ReportRun::query()->count())->toBe(0);
});
