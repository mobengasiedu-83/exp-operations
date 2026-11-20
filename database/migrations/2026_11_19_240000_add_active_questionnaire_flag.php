<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mark the questionnaire version that new calls must use. The simplified
     * blueprint removes approval workflows, so the active flag replaces the
     * clinical/privacy approval gate without deleting the retained audit columns.
     */
    public function up(): void
    {
        Schema::table('screening_protocols', function (Blueprint $table): void {
            $table->boolean('is_active')->default(false)->after('status')->index();
        });

        $approved = DB::table('screening_protocols')
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->value('id');

        if ($approved !== null) {
            DB::table('screening_protocols')->where('id', $approved)->update([
                'is_active' => true,
                'status' => 'active',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('screening_protocols', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }
};
