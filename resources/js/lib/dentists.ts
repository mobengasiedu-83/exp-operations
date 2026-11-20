export function coverageLabel(state: string): string {
    const labels: Record<string, string> = {
        rostered: 'Rostered',
        on_break: 'On break',
        on_leave: 'On leave',
        unavailable: 'Unavailable',
        not_rostered: 'Not rostered',
        inactive: 'Inactive',
    };

    return labels[state] ?? state;
}

export function coverageClasses(state: string): string {
    if (state === 'rostered') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (state === 'on_break') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
}

export function credentialLabel(state: string): string {
    const labels: Record<string, string> = {
        valid: 'Valid',
        expiring: 'Expiring soon',
        expired: 'Expired',
        missing: 'No credential',
        no_expiry: 'No expiry',
    };

    return labels[state] ?? state;
}

export function credentialClasses(state: string): string {
    if (state === 'expired') {
        return 'bg-rose-100 text-rose-800';
    }

    if (state === 'expiring') {
        return 'bg-amber-100 text-amber-800';
    }

    if (state === 'valid') {
        return 'bg-emerald-100 text-emerald-800';
    }

    return 'bg-slate-100 text-slate-700';
}

export function percent(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : `${Math.round(value * 100)}%`;
}

/**
 * Format a Date for a datetime-local input in the operator's timezone.
 */
export function toDateTimeInput(date: Date): string {
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);

    return local.toISOString().slice(0, 16);
}
