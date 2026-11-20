<?php

namespace App\Services;

use App\Models\DentistRoster;
use Carbon\CarbonImmutable;

/**
 * Builds the standard EXP rosters from the published service window.
 *
 * A roster can only promise cover the line actually offers: Monday to Friday,
 * 10:00–14:00 Ghana time, and never on a Ghana public holiday. The planner is
 * pure so the preview on the roster screen and the generated rows cannot drift.
 */
class RosterPlanner
{
    public function __construct(private ServiceCalendar $calendar) {}

    /**
     * @param  list<int>  $dentistIds
     * @return list<array{dentist_id: int, kind: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, service_date: string, notes: string}>
     */
    public function plan(array $dentistIds, CarbonImmutable $from, int $weeks): array
    {
        $windows = [];
        $start = $from->setTimezone($this->calendar->timezone())->startOfDay();
        $days = max($weeks, 1) * 7;
        [$openHour, $openMinute] = array_map('intval', explode(':', $this->calendar->opensAt()));
        [$closeHour, $closeMinute] = array_map('intval', explode(':', $this->calendar->closesAt()));

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->addDays($offset);

            if (! in_array($date->dayOfWeekIso, $this->calendar->opensOn(), true) || $this->calendar->holidayName($date) !== null) {
                continue;
            }

            $opensAt = $date->setTime($openHour, $openMinute);
            $closesAt = $date->setTime($closeHour, $closeMinute);

            foreach ($dentistIds as $dentistId) {
                $windows[] = [
                    'dentist_id' => $dentistId,
                    'kind' => 'shift',
                    'starts_at' => $opensAt->utc(),
                    'ends_at' => $closesAt->utc(),
                    'service_date' => $date->toDateString(),
                    'notes' => 'Standard EXP roster · '.$this->calendar->opensAt().'–'.$this->calendar->closesAt().' Ghana time',
                ];
            }
        }

        return $windows;
    }

    /**
     * The service days the planner will cover, for an honest preview.
     *
     * @return list<array{date: string, weekday: string, covered: bool, reason: string|null}>
     */
    public function preview(CarbonImmutable $from, int $weeks): array
    {
        $preview = [];
        $start = $from->setTimezone($this->calendar->timezone())->startOfDay();
        $days = max($weeks, 1) * 7;

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->addDays($offset);
            $holiday = $this->calendar->holidayName($date);
            $staffed = in_array($date->dayOfWeekIso, $this->calendar->opensOn(), true);
            $preview[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->format('D'),
                'covered' => $staffed && $holiday === null,
                'reason' => match (true) {
                    $holiday !== null => $holiday,
                    ! $staffed => 'Weekend',
                    default => null,
                },
            ];
        }

        return $preview;
    }

    public function shiftKind(): string
    {
        return DentistRoster::COVERING_KINDS[0];
    }
}
