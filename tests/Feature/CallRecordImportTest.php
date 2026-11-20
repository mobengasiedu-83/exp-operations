<?php

use App\Models\CdrRecord;
use App\Models\CelRecord;
use App\Models\IntegrationHealth;

function callExport(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'exp-import-');
    file_put_contents($path, implode("\n", array_map(fn ($row) => json_encode($row), $rows)));

    return $path;
}

test('call record imports normalise timestamps and tolerate replay', function () {
    $file = callExport([['uniqueid' => 'one', 'calldate' => '2026-01-01 10:00:00', 'duration' => 30, 'billsec' => 20, 'src' => '0241234567']]);
    try {
        foreach ([1, 2] as $attempt) {
            $this->artisan('exp:import-call-records', ['type' => 'cdr', 'file' => $file, '--timezone' => 'Africa/Accra'])->assertSuccessful();
        }
        expect(CdrRecord::count())->toBe(1);
        expect(CdrRecord::firstOrFail()->start->format('Y-m-d H:i:s'))->toBe('2026-01-01 10:00:00');
        expect(CdrRecord::firstOrFail()->raw['src'])->toBe('[REDACTED]');
    } finally {
        unlink($file);
    }
});

test('CEL imports support multiple events per channel and convert source timezone', function () {
    $file = callExport([
        ['uniqueid' => 'one', 'eventtype' => 'CHAN_START', 'eventtime' => '2026-01-01 10:00:00'],
        ['uniqueid' => 'one', 'eventtype' => 'HANGUP', 'eventtime' => '2026-01-01 10:00:30'],
    ]);
    try {
        $this->artisan('exp:import-call-records', ['type' => 'cel', 'file' => $file, '--timezone' => 'Africa/Lagos'])->assertSuccessful();
        expect(CelRecord::count())->toBe(2);
        expect(CelRecord::firstOrFail()->event_time)->toBe('2026-01-01 09:00:00');
    } finally {
        unlink($file);
    }
});

test('bad source clocks rollback the entire export and record an alert', function () {
    $file = callExport([
        ['uniqueid' => 'valid', 'start' => '2026-01-01 10:00:00'],
        ['uniqueid' => 'future', 'start' => '2099-01-01 10:00:00'],
    ]);
    try {
        $this->artisan('exp:import-call-records', ['type' => 'cdr', 'file' => $file, '--timezone' => 'Africa/Accra'])->assertFailed();
        expect(CdrRecord::count())->toBe(0);
        expect(IntegrationHealth::firstOrFail()->status)->toBe('unhealthy');
    } finally {
        unlink($file);
    }
});

test('imports require an explicit source timezone', function () {
    $file = callExport([['uniqueid' => 'one', 'start' => '2026-01-01 10:00:00']]);
    try {
        $this->artisan('exp:import-call-records', ['type' => 'cdr', 'file' => $file])->assertFailed();
        expect(CdrRecord::count())->toBe(0);
    } finally {
        unlink($file);
    }
});

test('invalid durations and timestamps are rejected without partial imports', function (array $invalid) {
    $file = callExport([['uniqueid' => 'bad', 'start' => '2026-01-01 10:00:00', ...$invalid]]);
    try {
        $this->artisan('exp:import-call-records', ['type' => 'cdr', 'file' => $file, '--timezone' => 'Africa/Accra'])->assertFailed();
        expect(CdrRecord::count())->toBe(0);
    } finally {
        unlink($file);
    }
})->with([
    'negative duration' => [['duration' => -1]],
    'billable duration too long' => [['duration' => 1, 'billsec' => 2]],
    'invalid calendar date' => [['start' => '2026-02-30 10:00:00']],
    'end before start' => [['end' => '2026-01-01 09:59:59']],
]);
