<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Consultation tariff
    |--------------------------------------------------------------------------
    |
    | Only used by the estimated consultation cost KPI. Leave at 0 until the
    | service owner approves a tariff: the report then publishes no monetary
    | value instead of a misleading zero cost.
    |
    */

    'consultation_tariff_per_minute' => (float) env('REPORTING_CONSULTATION_TARIFF_PER_MINUTE', 0),

    /*
    |--------------------------------------------------------------------------
    | Maximum reporting window
    |--------------------------------------------------------------------------
    |
    | A manual report is bounded so a single request can never scan the entire
    | call archive. Longer periods belong to a scheduled run with its own
    | approval.
    |
    */

    'max_window_days' => (int) env('REPORTING_MAX_WINDOW_DAYS', 92),

];
