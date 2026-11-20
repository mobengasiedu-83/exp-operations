<?php

return [

    /*
    |--------------------------------------------------------------------------
    | EXP service window
    |--------------------------------------------------------------------------
    |
    | The line is staffed Monday to Friday, 10:00 to 14:00 Ghana time, except on
    | Ghana public holidays. Dentist rosters are generated from this window so a
    | roster can never promise cover the line does not offer.
    |
    */

    'timezone' => env('EXP_SERVICE_TIMEZONE', 'Africa/Accra'),

    'opens_at' => env('EXP_SERVICE_OPENS_AT', '10:00'),

    'closes_at' => env('EXP_SERVICE_CLOSES_AT', '14:00'),

    /** ISO-8601 weekdays the line is staffed (1 = Monday, 7 = Sunday). */
    'days' => [1, 2, 3, 4, 5],

    /*
    | Eid al-Fitr and Eid al-Adha follow the lunar calendar, so they are
    | confirmed by the Office of the Chief Imam each year. Add the confirmed
    | dates here before each year starts; an unlisted year simply has no Eid
    | rows rather than a guessed date.
    */
    'eid' => [
        2026 => [
            '2026-03-20' => 'Eid al-Fitr',
            '2026-03-21' => 'Shaqq Day',
            '2026-05-27' => 'Eid al-Adha',
        ],
    ],

    /** Declared observance dates override the calculated calendar; null removes a moved date. */
    'holiday_overrides' => [
        2026 => [
            '2026-01-07' => null,
            '2026-01-09' => 'Constitution Day (observed)',
            '2026-07-01' => null,
            '2026-07-03' => 'Republic Day (observed)',
        ],
    ],

];
