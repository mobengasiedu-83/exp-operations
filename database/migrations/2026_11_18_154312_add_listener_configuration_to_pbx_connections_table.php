<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pbx_connections', function (Blueprint $table) {
            $table->string('ami_username')->nullable();
            $table->text('ami_password')->nullable();
            $table->boolean('listener_enabled')->default(true);
            $table->unsignedBigInteger('reconnect_generation')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pbx_connections', function (Blueprint $table) {
            $table->dropColumn(['ami_username', 'ami_password', 'listener_enabled', 'reconnect_generation']);
        });
    }
};
