<?php

use App\Models\CallEvent;
use App\Models\Interaction;
use App\Models\PbxConnection;

function ivrExportFile(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'exp-ivr-');
    file_put_contents($path, implode("\n", array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows)));

    return $path;
}

function ivrImportConnection(): PbxConnection
{
    $connection = PbxConnection::create(['name' => 'ivr-import', 'host' => 'localhost', 'ami_port' => 5038]);
    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'Context' => 'exp-inbound'],
        ['t' => 60, 'Event' => 'Hangup', 'Uniqueid' => 'caller', 'Linkedid' => 'caller'],
    ]);

    return $connection;
}

test('stored pbx selections recover an outage journey and replay without duplicates', function () {
    ivrImportConnection();
    $row = ['uniqueid' => 'caller', 'menu' => 'IVR-2', 'option' => '3', 'occurred_at' => '2026-01-01 10:00:10', 'caller' => '+233531005130'];
    $file = ivrExportFile([$row, [...$row, 'occurred_at' => '2026-01-01 10:00:30']]);

    try {
        foreach ([1, 2] as $attempt) {
            $this->artisan('exp:import-ivr-records', ['file' => $file, '--connection' => 'ivr-import', '--timezone' => 'Africa/Accra'])->assertSuccessful();
        }

        $interaction = Interaction::firstOrFail();
        expect($interaction->ivrSteps()->count())->toBe(2)
            ->and($interaction->ivrSteps()->pluck('option')->all())->toBe(['3', '3'])
            ->and($interaction->status)->toBe('completed')
            ->and(CallEvent::where('event_name', 'IvrSelection')->count())->toBe(2)
            ->and(json_encode(CallEvent::where('event_name', 'IvrSelection')->firstOrFail()->data))->not->toContain('233531005130');
    } finally {
        unlink($file);
    }
});

test('invalid or unmatched exports fail atomically', function (array $invalid) {
    ivrImportConnection();
    $row = ['uniqueid' => 'caller', 'menu' => 'IVR-2', 'option' => '1', 'occurred_at' => '2026-01-01 10:00:10'];
    $file = ivrExportFile([$row, [...$row, ...$invalid]]);

    try {
        $this->artisan('exp:import-ivr-records', ['file' => $file, '--connection' => 'ivr-import', '--timezone' => 'Africa/Accra'])->assertFailed();

        expect(CallEvent::where('event_name', 'IvrSelection')->count())->toBe(0)
            ->and(Interaction::firstOrFail()->ivrSteps()->count())->toBe(0);
    } finally {
        unlink($file);
    }
})->with([
    'unknown leg' => [['uniqueid' => 'unknown']],
    'out of interval' => [['occurred_at' => '2026-01-01 11:00:00']],
    'invalid date' => [['occurred_at' => '2026-02-30 10:00:00']],
    'future clock' => [['occurred_at' => '2099-01-01 10:00:00']],
    'invalid option' => [['option' => 'hello']],
]);

test('the importer converts timezone and collapses duplicate observations of one press', function () {
    ivrImportConnection();
    $row = ['uniqueid' => 'caller', 'menu' => 'IVR-2', 'option' => '*3', 'occurred_at' => '2026-01-01 11:00:10'];
    $file = ivrExportFile([$row, [...$row, 'occurred_at' => '2026-01-01 11:00:11']]);

    try {
        $this->artisan('exp:import-ivr-records', ['file' => $file, '--connection' => 'ivr-import', '--timezone' => 'Africa/Lagos'])->assertSuccessful();

        $steps = Interaction::firstOrFail()->ivrSteps()->get();
        expect($steps)->toHaveCount(1)
            ->and($steps->first()->occurred_at->format('H:i:s'))->toBe('10:00:10')
            ->and($steps->first()->option)->toBe('*3');
    } finally {
        unlink($file);
    }
});

test('an export cannot attach a caller from another pbx connection', function () {
    ivrImportConnection();
    PbxConnection::create(['name' => 'other', 'host' => 'localhost', 'ami_port' => 5038]);
    $file = ivrExportFile([['uniqueid' => 'caller', 'menu' => 'IVR-2', 'option' => '3', 'occurred_at' => '2026-01-01 10:00:10']]);

    try {
        $this->artisan('exp:import-ivr-records', ['file' => $file, '--connection' => 'other', '--timezone' => 'Africa/Accra'])->assertFailed();
        expect(CallEvent::where('event_name', 'IvrSelection')->exists())->toBeFalse();
    } finally {
        unlink($file);
    }
});
