<?php

use App\Models\CallerCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table): void {
            $table->string('preferred_name_hash', 64)->nullable()->index();
        });
        DB::table('cases')->whereNotNull('preferred_name')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('cases')->where('id', $row->id)->update(['preferred_name_hash' => CallerCase::nameFingerprint(Crypt::decryptString($row->preferred_name))]);
            }
        });
        Schema::table('screening_answers', function (Blueprint $table): void {
            $table->text('answer')->change();
        });
        Schema::table('screening_drafts', function (Blueprint $table): void {
            $table->longText('answers')->change();
        });
        foreach (['screening_answers' => 'answer', 'screening_drafts' => 'answers'] as $table => $column) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([$column => Crypt::encryptString($row->{$column})]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table): void {
            $table->dropColumn('preferred_name_hash');
        });
        foreach (['screening_answers' => 'answer', 'screening_drafts' => 'answers'] as $table => $column) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([$column => Crypt::decryptString($row->{$column})]);
                }
            });
        }
        Schema::table('screening_drafts', function (Blueprint $table): void {
            $table->json('answers')->change();
        });
    }
};
