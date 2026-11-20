export function formatClock(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    const total = Math.max(0, Math.round(seconds));
    const minutes = Math.floor(total / 60);
    const remainder = total % 60;

    return `${minutes}:${String(remainder).padStart(2, '0')}`;
}

export function formatSeconds(seconds: number | null | undefined): string {
    return seconds === null || seconds === undefined ? '—' : `${Math.max(0, Math.round(seconds))}s`;
}

export function freshnessClasses(state: string): string {
    return state === 'live'
        ? 'bg-emerald-100 text-emerald-800'
        : state === 'stale'
          ? 'bg-amber-100 text-amber-800'
          : 'bg-rose-100 text-rose-800';
}

export function severityClasses(severity: string): string {
    return severity === 'critical'
        ? 'bg-rose-100 text-rose-800'
        : severity === 'warning'
          ? 'bg-amber-100 text-amber-800'
          : 'bg-slate-100 text-slate-700';
}

export function stageLabel(stage: string): string {
    const labels: Record<string, string> = {
        setup: 'Setup',
        ringing: 'Ringing',
        agent_conversation: 'Agent conversation',
        dentist_ringing: 'Dentist ringing',
        dentist_connected: 'Dentist connected',
        private_handover: 'Private handover',
        consultation: 'Consultation',
    };

    return labels[stage] ?? stage;
}

export function stageClasses(stage: string): string {
    if (stage === 'consultation' || stage === 'dentist_connected') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (stage === 'private_handover' || stage === 'dentist_ringing') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
}
