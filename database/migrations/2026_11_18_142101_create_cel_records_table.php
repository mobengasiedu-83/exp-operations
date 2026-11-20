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
        Schema::create('cel_records', function (Blueprint $table) {
            $table->id();
            $table->string('event_type')->nullable()->index();
            $table->string('event_time')->nullable();
            $table->string('uniqueid')->nullable()->index();
            $table->string('linkedid')->nullable()->index();
            $table->string('tenantid')->nullable();
            $table->string('peer')->nullable();
            $table->string('peerinbound')->nullable();
            $table->string('peeroutbound')->nullable();
            $table->string('peerport')->nullable();
            $table->string('peerformat')->nullable();
            $table->string('peerhost')->nullable();
            $table->string('channel')->nullable()->index();
            $table->string('channelstate')->nullable();
            $table->string('channelstatedesc')->nullable();
            $table->string('calleridnum')->nullable();
            $table->string('calleridname')->nullable();
            $table->string('associateduniqueid')->nullable();
            $table->string('associatedcalleeidnum')->nullable();
            $table->string('associatedcalleeidname')->nullable();
            $table->string('accountcode')->nullable();
            $table->string('exten')->nullable();
            $table->string('context')->nullable();
            $table->string('appname')->nullable();
            $table->string('appdata')->nullable();
            $table->string('amaflags')->nullable();
            $table->string('account')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cel_records');
    }
};
