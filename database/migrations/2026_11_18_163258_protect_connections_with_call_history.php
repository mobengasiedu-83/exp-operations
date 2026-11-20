<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interactions', function (Blueprint $table): void {
            $table->dropForeign(['pbx_connection_id']);
            $table->foreign('pbx_connection_id')->references('id')->on('pbx_connections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('interactions', function (Blueprint $table): void {
            $table->dropForeign(['pbx_connection_id']);
            $table->foreign('pbx_connection_id')->references('id')->on('pbx_connections')->cascadeOnDelete();
        });
    }
};
