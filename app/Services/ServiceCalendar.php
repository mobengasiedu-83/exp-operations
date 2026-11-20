<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The published EXP service window: Monday to Friday, 10:00–14:00 Ghana time,
 * closed on Ghana public holidays.
 *
 * The calendar is deterministic so rostering, dashboards and the caller-facing
 * wording all agree. Ghana observes a fixed holiday that falls at the weekend
 * on the following Monday, and Eid dates are published by the Office of the
 * Chief Imam each year — an unconfirmed year simply has no Eid rows rather than
 * an invented date.
 */
class ServiceCalendar
{
    public function timezone(): string
    {
        return (string) config('exp_service.timezone', 'Africa/Accra');
    }

    public function opensAt(): string
    {
        return (string) config('exp_service.opens_at', '10:00');
    }

    public function closesAt(): string
    {
        return (string) config('exp_service.closes_at', '14:00');
    }

    /** @return list<int> */
    public function opensOn(): array
    {
        /** @var list<int> $days */
        $days = array_values(array_map('intval', (array) config('exp_service.days', [1, 2, 3, 4, 5])));

        return $days;
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /**
     * Every public holiday in the year, including observed Mondays.
     *
     * @return array<string, string> ISO date => label, sorted by date
     */
    public function holidays(int $year): array
    {
        $holidays = [
            $year.'-01-01' => "New Year's Day",
            $year.'-01-07' => 'Constitution Day',
            $year.'-03-06' => 'Independence Day',
            $year.'-05-01' => 'May Day (Workers’ Day)',
            $year.'-07-01' => 'Republic Day',
            $year.'-09-21' => 'Founder’s Day',
            $year.'-12-25' => 'Christmas Day',
            $year.'-12-26' => 'Boxing Day',
        ];

        $easter = $this->easterSunday($year);
        $holidays[$easter->subDays(2)->toDateString()] = 'Good Friday';
        $holidays[$easter->addDay()->toDateString()] = 'Easter Monday';

        $december = CarbonImmutable::create($year, 12, 1, 0, 0, 0, $this->timezone());
        $farmersDay = $december->dayOfWeek === CarbonInterface::FRIDAY ? $december : $december->next(CarbonInterface::FRIDAY);
        $holidays[$farmersDay->toDateString()] = 'Farmers’ Day';

        /** @var array<string, string> $eid */
        $eid = (array) config('exp_service.eid.'.$year, []);
        foreach ($eid as $date => $label) {
            $holidays[$date] = $label;
        }

        foreach ($holidays as $date => $label) {
            $day = CarbonImmutable::createFromFormat('Y-m-d', $date, $this->timezone());
            if ($day === false) {
                continue;
            }

            $observed = match ($day->dayOfWeek) {
                CarbonInterface::SATURDAY => $day->addDays(2),
                CarbonInterface::SUNDAY => $day->addDay(),
                default => null,
            };

            if ($observed !== null) {
                $holidays[$observed->toDateString()] ??= $label.' (observed)';
            }
        }

        /** @var array<string, string|null> $overrides */
        $overrides = (array) config('exp_service.holiday_overrides.'.$year, []);
        foreach ($overrides as $date => $label) {
            if ($label === null) {
                unset($holidays[$date]);
            } else {
                $holidays[$date] = $label;
            }
        }

        ksort($holidays);

        return $holidays;
    }

    public function holidayName(CarbonInterface $at): ?string
    {
        $local = CarbonImmutable::instance($at)->setTimezone($this->timezone());

        return $this->holidays($local->year)[$local->toDateString()] ?? null;
    }

    public function isOpenAt(?CarbonInterface $at = null): bool
    {
        return $this->state($at)['state'] === 'open';
    }

    /**
     * Published state of the line at a moment, with the wording the UI prints.
     *
     * @return array{state: string, label: string, reason: string, opens_at: string, closes_at: string, next_opening_at: string|null, next_opening_label: string|null, holiday: string|null}
     */
    public function state(?CarbonInterface $at = null): array
    {
        $moment = CarbonImmutable::instance($at ?? $this->now())->setTimezone($this->timezone());
        $holiday = $this->holidayName($moment);
        $staffedDay = in_array($moment->dayOfWeekIso, $this->opensOn(), true);
        $time = $moment->format('H:i');
        $opensAt = $this->opensAt();
        $closesAt = $this->closesAt();

        [$state, $label, $reason] = match (true) {
            $holiday !== null => ['holiday', 'Closed — '.$holiday, 'The line does not run on Ghana public holidays.'],
            ! $staffedDay => ['weekend', 'Closed today', 'The line runs Monday to Friday.'],
            $time < $opensAt => ['before_hours', 'Opens at '.$opensAt, 'The line opens at '.$opensAt.' Ghana time.'],
            $time >= $closesAt => ['after_hours', 'Closed for today', 'The line closes at '.$closesAt.' Ghana time.'],
            default => ['open', 'Open now', 'The line closes at '.$closesAt.' Ghana time.'],
        };

        $next = $state === 'open' ? null : $this->nextOpening($moment);

        return [
            'state' => $state,
            'label' => $label,
            'reason' => $reason,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'next_opening_at' => $next?->toIso8601String(),
            'next_opening_label' => $next?->format('D j M').' at '.$opensAt,
            'holiday' => $holiday,
        ];
    }

    /**
     * The next moment the line opens, or null when nothing is schedulable.
     */
    public function nextOpening(?CarbonInterface $at = null): ?CarbonImmutable
    {
        $moment = CarbonImmutable::instance($at ?? $this->now())->setTimezone($this->timezone());
        [$hour, $minute] = array_map('intval', explode(':', $this->opensAt()));

        for ($offset = 0; $offset <= 366; $offset++) {
            $date = $moment->startOfDay()->addDays($offset);

            if ($this->holidayName($date) !== null || ! in_array($date->dayOfWeekIso, $this->opensOn(), true)) {
                continue;
            }

            $candidate = $date->setTime($hour, $minute);

            if ($candidate->greaterThan($moment)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Public holidays from today onwards, for the roster and service banners.
     *
     * @return list<array{date: string, label: string, weekday: string}>
     */
    public function upcomingHolidays(int $limit = 4, ?CarbonInterface $at = null): array
    {
        $moment = CarbonImmutable::instance($at ?? $this->now())->setTimezone($this->timezone());
        $upcoming = [];

        foreach ([$moment->year, $moment->year + 1] as $year) {
            foreach ($this->holidays($year) as $date => $label) {
                if ($date < $moment->toDateString()) {
                    continue;
                }

                $day = CarbonImmutable::createFromFormat('Y-m-d', $date, $this->timezone());
                $upcoming[$date] = [
                    'date' => $date,
                    'label' => $label,
                    'weekday' => $day === false ? '' : $day->format('D'),
                ];
            }
        }

        ksort($upcoming);

        return array_slice(array_values($upcoming), 0, max($limit, 1));
    }

    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, $this->timezone());
    }
}
