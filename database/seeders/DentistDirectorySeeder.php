<?php

namespace Database\Seeders;

use App\Models\Dentist;
use App\Models\DentistNumber;
use App\Models\DentistRoster;
use App\Services\GhanaPhoneNumber;
use App\Services\RosterPlanner;
use App\Services\ServiceCalendar;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

/**
 * Seeds the practice dentists and their mobiles from the practice contact list.
 *
 * The applications records and tracks calls against the directory, so each
 * number is stored normalised to +233XXXXXXXXX (the form the PBX reports
 * inbound and outbound legs in) while the entry exactly as supplied is kept in
 * `original` for display. Numbers are approved and primary so they are usable
 * for transfer selection straight away.
 *
 * Re-running the seeder is safe: dentists are matched by name and numbers by
 * their normalised value, so no duplicates are created.
 */
class DentistDirectorySeeder extends Seeder
{
    /**
     * @var list<array{name: string, number: string, notes?: string}>
     */
    public const DENTISTS = [
        ['name' => 'Dr Paapa Puplampu', 'number' => '0556760659'],
        ['name' => 'Dr Gwendolyn Amarquaye', 'number' => '0593989921'],
        ['name' => 'Dr Kakraba Quarshie', 'number' => '0556760650'],
        ['name' => 'Dr Richard Atuwo', 'number' => '0556760651'],
        ['name' => 'Dr Philip Frimpong', 'number' => '0257956504'],
        ['name' => 'Dr Marrie', 'number' => '0531015455', 'notes' => 'Surname to be confirmed from the practice contact list.'],
        ['name' => 'Dr Asiamah', 'number' => '0531015150', 'notes' => 'Surname to be confirmed from the practice contact list.'],
    ];

    public function run(): void
    {
        foreach (self::DENTISTS as $entry) {
            $number = GhanaPhoneNumber::normalize($entry['number']);

            if ($number === null) {
                throw new InvalidArgumentException(
                    "The seeded number for {$entry['name']} is not a valid Ghana mobile number: {$entry['number']}"
                );
            }

            $dentist = Dentist::query()->firstOrCreate(
                ['name' => $entry['name']],
                ['active' => true, 'notes' => $entry['notes'] ?? null],
            );

            DentistNumber::query()->firstOrCreate(
                ['number' => $number],
                [
                    'dentist_id' => $dentist->id,
                    'original' => $entry['number'],
                    'label' => 'Mobile',
                    'is_primary' => ! $dentist->numbers()->where('is_primary', true)->exists(),
                    'status' => 'approved',
                    'verified_at' => now(),
                ],
            );
        }

        $this->seedStandardRosters();
    }

    /**
     * The practice runs Monday to Friday, 10:00–14:00, closed on Ghana public
     * holidays. Seeding the current and next service week makes the roster and
     * availability screens usable straight after install; re-running the
     * seeder never duplicates a window.
     */
    private function seedStandardRosters(): void
    {
        $calendar = app(ServiceCalendar::class);
        $planner = app(RosterPlanner::class);
        $dentistIds = [];

        foreach (Dentist::query()->where('active', true)->orderBy('name')->pluck('id') as $id) {
            $dentistIds[] = (int) $id;
        }

        $from = $calendar->now()->startOfWeek();

        foreach ($planner->plan($dentistIds, $from, 2) as $window) {
            DentistRoster::query()->firstOrCreate(
                [
                    'dentist_id' => $window['dentist_id'],
                    'starts_at' => $window['starts_at'],
                    'ends_at' => $window['ends_at'],
                ],
                ['kind' => $window['kind'], 'notes' => $window['notes']],
            );
        }
    }
}
