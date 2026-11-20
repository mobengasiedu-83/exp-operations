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
        Schema::create('cdr_records', function (Blueprint $table) {
            $table->id();
            $table->string('accountcode')->nullable()->index();
            $table->string('src')->nullable();
            $table->string('dst')->nullable();
            $table->string('dcontext')->nullable();
            $table->string('clrd')->nullable();
            $table->string('channel')->nullable()->index();
            $table->string('dstchannel')->nullable();
            $table->string('lastapp')->nullable();
            $table->string('lastdata')->nullable();
            $table->timestamp('start')->nullable()->index();
            $table->timestamp('answer')->nullable();
            $table->timestamp('end')->nullable();
            $table->integer('duration')->nullable();
            $table->integer('billsec')->nullable();
            $table->string('disposition')->nullable()->index();
            $table->string('amaflags')->nullable();
            $table->string('uniqueid')->nullable()->index();
            $table->string('userfield')->nullable();
            $table->string('linkedid')->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cdr_records');
    }
};
