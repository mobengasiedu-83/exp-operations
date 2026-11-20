<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Caller IDs are still never stored in full. The listener keeps only a
     * masked caller number plus a keyed fingerprint so call history can show
     * and search the caller without retaining the raw number.
     */
    public function up(): void
    {
        Schema::table('call_legs', function (Blueprint $table): void {
            $table->string('caller_masked', 64)->nullable()->after('inbound_masked');
            $table->string('caller_hash', 64)->nullable()->after('caller_masked')->index();
        });
    }

    public function down(): void
    {
        Schema::table('call_legs', function (Blueprint $table): void {
            $table->dropColumn(['caller_hash', 'caller_masked']);
        });
    }
};
