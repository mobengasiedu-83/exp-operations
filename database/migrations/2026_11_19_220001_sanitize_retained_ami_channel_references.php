<?php

use App\Services\GhanaPhoneNumber;
use App\Services\PbxTelemetry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $redactor = app(PbxTelemetry::class);
        foreach (['ami_events' => ['payload', 'correlation_data'], 'call_events' => ['data'], 'call_legs' => []] as $table => $jsonColumns) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $jsonColumns, $redactor): void {
                foreach ($rows as $row) {
                    $values = [];
                    if (property_exists($row, 'channel') && $row->channel !== null) {
                        $channel = GhanaPhoneNumber::redactChannel($row->channel);
                        if ($channel !== $row->channel) {
                            $values['channel'] = $channel;
                        }
                    }
                    foreach ($jsonColumns as $column) {
                        if ($row->{$column} !== null) {
                            $decoded = json_decode($row->{$column}, true, 512, JSON_THROW_ON_ERROR);
                            $sanitised = $redactor->redact($decoded);
                            if ($sanitised !== $decoded) {
                                $values[$column] = json_encode($sanitised, JSON_THROW_ON_ERROR);
                            }
                        }
                    }
                    if ($values !== []) {
                        DB::table($table)->where('id', $row->id)->update($values);
                    }
                }
            });
        }
    }

    /** Sanitisation deliberately does not restore sensitive channel digits. */
    public function down(): void {}
};
