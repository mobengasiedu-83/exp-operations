<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connection_health', function (Blueprint $table): void {
            $table->unsignedInteger('reconnect_attempts')->default(0)->change();
            $table->unsignedInteger('lag_seconds')->nullable()->change();
        });
        foreach (['cdr_records', 'cel_records'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('source_fingerprint', 64)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (['cdr_records', 'cel_records'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropUnique(['source_fingerprint']);
                $table->dropColumn('source_fingerprint');
            });
        }
    }
};
