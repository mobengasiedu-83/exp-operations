export type DraftAnswerPayload = {
    question_id: number;
    answer: string;
};

export type ScreeningDraftBuffer = {
    protocol_id: number;
    priority: string;
    answers: DraftAnswerPayload[];
    saved_at: string;
};

export function draftStorageKey(caseId: number, protocolId: number): string {
    return `exp:screening-draft:${caseId}:${protocolId}`;
}

/**
 * Session-scoped recovery buffer. It never outlives the browser tab, and it is
 * cleared as soon as the server confirms the answers are durable.
 */
export function readDraftBuffer(key: string): ScreeningDraftBuffer | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const raw = window.sessionStorage.getItem(key);

        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw) as ScreeningDraftBuffer;

        return Array.isArray(parsed.answers) ? parsed : null;
    } catch {
        return null;
    }
}

export function writeDraftBuffer(key: string, buffer: ScreeningDraftBuffer): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.setItem(key, JSON.stringify(buffer));
    } catch {
        // A full or blocked session storage must never interrupt call handling.
    }
}

export function clearDraftBuffer(key: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.removeItem(key);
    } catch {
        // Ignore storage failures; the server copy remains authoritative.
    }
}

export function readXsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
