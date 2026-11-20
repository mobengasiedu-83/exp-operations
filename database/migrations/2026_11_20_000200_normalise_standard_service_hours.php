<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring an existing "Standard Hours" row in line with the published window.
 *
 * The original seed used 08:00–17:00 every day. Only a row that still holds
 * those untouched values is updated, so a deliberately edited window is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_hours')
            ->where('name', 'Standard Hours')
            ->where('opens_at', 'like', '08:00%')
            ->where('closes_at', 'like', '17:00%')
            ->update([
                'opens_at' => '10:00:00',
                'closes_at' => '14:00:00',
                'days' => json_encode([1, 2, 3, 4, 5]),
                'description' => 'Mon–Fri, 10:00–14:00 Ghana time. Closed on Ghana public holidays.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The previous window cannot be restored without overwriting an edit.
    }
};
