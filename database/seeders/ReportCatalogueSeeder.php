<?php

namespace Database\Seeders;

use App\Models\ReportDefinition;
use App\Services\Reporting;
use Illuminate\Database\Seeder;

/**
 * Publishes the operational report catalogue.
 *
 * The catalogue is code-owned so every install publishes the same KPI
 * definitions. An operator may disable a definition, but re-seeding never
 * re-enables it and never rewrites the stored numerator/denominator wording
 * without a visible revision bump in the report history.
 */
class ReportCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Reporting::definitions() as $definition) {
            ReportDefinition::query()->updateOrCreate(
                ['key' => $definition['key']],
                $definition,
            );
        }
    }
}
