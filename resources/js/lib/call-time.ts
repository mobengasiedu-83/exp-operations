/**
 * Formatting helpers for the Call time menu.
 *
 * Time is reported in seconds by the API so a caller's wait, an agent's talk
 * time and a dentist's connected time can all be summed without rounding
 * drift. These helpers only change how the total is printed.
 */
export function formatTime(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    const total = Math.max(0, Math.round(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const remainder = total % 60;

    if (hours > 0) {
        return `${hours}h ${String(minutes).padStart(2, '0')}m`;
    }

    if (minutes > 0) {
        return `${minutes}m ${String(remainder).padStart(2, '0')}s`;
    }

    return `${remainder}s`;
}

/** A day bucket from the overview, printed as an unambiguous local date. */
export function formatDay(date: string): string {
    const parsed = new Date(`${date}T00:00:00`);

    return Number.isNaN(parsed.getTime())
        ? date
        : parsed.toLocaleDateString(undefined, {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
          });
}

/** Share of the window's total call time, used by the stage breakdown. */
export function share(value: number, total: number): number {
    return total <= 0 ? 0 : Math.min(100, (value / total) * 100);
}
