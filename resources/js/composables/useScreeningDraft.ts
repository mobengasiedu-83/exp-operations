import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    clearDraftBuffer,
    readDraftBuffer,
    readXsrfToken,
    writeDraftBuffer,
    type DraftAnswerPayload,
} from '@/lib/screening-draft';

export type DraftSaveState =
    | 'idle'
    | 'saved'
    | 'saving'
    | 'unsaved'
    | 'offline'
    | 'conflict'
    | 'error';

export type ScreeningDraftAnswer = DraftAnswerPayload & {
    answer_status: string;
    question_version: string | null;
    recorded_by: number | null;
    saved_at: string | null;
};

export type ScreeningDraft = {
    id: number;
    screening_protocol_id: number;
    priority: string;
    revision: number;
    recorded_by: number | null;
    draft_saved_at: string | null;
    answers: ScreeningDraftAnswer[];
};

type Options = {
    url: string;
    storageKey: string;
    enabled: () => boolean;
    payload: () => {
        protocol_id: number;
        priority: string;
        answers: DraftAnswerPayload[];
    };
    revision: () => number | null;
    savedAt: () => string | null;
    savedBy: () => number | null;
    onRestore?: (buffer: {
        answers: DraftAnswerPayload[];
        priority: string;
    }) => void;
};

const DEBOUNCE_MS = 1200;

function clock(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString();
}

/**
 * Debounced autosave for the approved questionnaire. Answers are buffered in
 * session storage while offline so a refresh or dropped connection never loses
 * what the caller reported.
 */
export function useScreeningDraft(options: Options) {
    const state = ref<DraftSaveState>(options.savedAt() ? 'saved' : 'idle');
    const savedAt = ref<string | null>(options.savedAt());
    const savedBy = ref<number | null>(options.savedBy());
    const revision = ref<number | null>(options.revision());
    const message = ref('');
    const online = ref(true);
    const recovered = ref(false);

    let timer: ReturnType<typeof setTimeout> | undefined;
    let inflight = false;
    let dirty = false;
    let mounted = false;

    const label = computed(() => {
        const at = clock(savedAt.value);

        switch (state.value) {
            case 'saving':
                return 'Saving answers…';
            case 'saved':
                return at ? `Answers saved ${at}` : 'Answers saved';
            case 'unsaved':
                return 'Unsaved changes';
            case 'offline':
                return 'Offline — answers kept in this browser';
            case 'conflict':
                return 'Answers changed in another window';
            case 'error':
                return 'Answers not saved — retry';
            default:
                return at ? `Answers saved ${at}` : 'Not saved yet';
        }
    });

    const tone = computed(() => {
        if (state.value === 'conflict' || state.value === 'error') {
            return 'text-red-600';
        }

        if (state.value === 'unsaved' || state.value === 'offline') {
            return 'text-amber-700 dark:text-amber-400';
        }

        return 'text-muted-foreground';
    });

    const canRetry = computed(
        () => state.value === 'error' || state.value === 'offline',
    );

    const conflict = computed(() => state.value === 'conflict');

    function buffer() {
        return {
            ...options.payload(),
            saved_at: new Date().toISOString(),
        };
    }

    function persist() {
        if (typeof window === 'undefined') {
            return;
        }

        writeDraftBuffer(options.storageKey, buffer());
    }

    function currentRevision(): number | null {
        const server = options.revision();

        if (server === null) {
            return revision.value;
        }

        return revision.value === null
            ? server
            : Math.max(server, revision.value);
    }

    function schedule(delay = DEBOUNCE_MS) {
        if (!mounted || !options.enabled() || state.value === 'conflict') {
            return;
        }

        if (timer) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => void flush(), delay);
    }

    async function flush() {
        if (!mounted || !options.enabled() || inflight) {
            return;
        }

        if (typeof navigator !== 'undefined' && !navigator.onLine) {
            state.value = 'offline';
            persist();

            return;
        }

        inflight = true;
        dirty = false;
        state.value = 'saving';

        try {
            const response = await fetch(options.url, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify({
                    ...options.payload(),
                    revision: currentRevision(),
                }),
            });

            if (response.status === 409) {
                const body = (await response.json().catch(() => null)) as {
                    message?: string;
                } | null;
                state.value = 'conflict';
                message.value =
                    body?.message ??
                    'These answers were saved in another window. Reload the case before continuing.';

                return;
            }

            if (!response.ok) {
                const body = (await response.json().catch(() => null)) as {
                    message?: string;
                } | null;
                state.value = 'error';
                message.value =
                    body?.message ??
                    'Answers could not be saved. Retry before closing.';
                persist();

                return;
            }

            const body = (await response.json()) as { draft: ScreeningDraft };
            revision.value = body.draft.revision;
            savedAt.value = body.draft.draft_saved_at;
            savedBy.value = body.draft.recorded_by;
            message.value = '';
            state.value = 'saved';
            clearDraftBuffer(options.storageKey);
        } catch {
            state.value = 'offline';
            message.value =
                'Connection lost. Answers are kept in this browser and will be retried.';
            persist();
        } finally {
            inflight = false;

            if (dirty) {
                schedule();
            }
        }
    }

    function onOnline() {
        online.value = true;

        if (state.value === 'offline') {
            void flush();
        }
    }

    function onOffline() {
        online.value = false;
        state.value = 'offline';
        persist();
    }

    function beforeUnload(event: BeforeUnloadEvent) {
        if (
            state.value === 'unsaved' ||
            state.value === 'saving' ||
            state.value === 'offline' ||
            state.value === 'error'
        ) {
            event.preventDefault();
            event.returnValue = '';
        }
    }

    watch(
        () => JSON.stringify(options.payload()),
        (next, previous) => {
            if (!mounted || !options.enabled() || next === previous) {
                return;
            }

            if (state.value === 'conflict') {
                return;
            }

            state.value = 'unsaved';
            dirty = true;
            persist();
            schedule();
        },
    );

    watch(
        () => options.revision(),
        (value) => {
            if (
                value !== null &&
                (revision.value === null || value > revision.value)
            ) {
                revision.value = value;
            }
        },
    );

    onMounted(() => {
        mounted = true;
        online.value =
            typeof navigator === 'undefined' ? true : navigator.onLine;

        if (typeof window !== 'undefined') {
            window.addEventListener('online', onOnline);
            window.addEventListener('offline', onOffline);
            window.addEventListener('beforeunload', beforeUnload);
        }

        if (!online.value) {
            state.value = 'offline';
        }

        const stored = readDraftBuffer(options.storageKey);
        const storedAt = stored ? new Date(stored.saved_at).getTime() : 0;
        const serverAt = savedAt.value ? new Date(savedAt.value).getTime() : 0;

        if (stored && options.onRestore && storedAt > serverAt) {
            options.onRestore({
                answers: stored.answers,
                priority: stored.priority,
            });
            recovered.value = true;
            state.value = 'unsaved';
            dirty = true;
            schedule(0);
        }
    });

    onBeforeUnmount(() => {
        mounted = false;

        if (timer) {
            clearTimeout(timer);
        }

        if (typeof window !== 'undefined') {
            window.removeEventListener('online', onOnline);
            window.removeEventListener('offline', onOffline);
            window.removeEventListener('beforeunload', beforeUnload);
        }
    });

    function markRecorded() {
        clearDraftBuffer(options.storageKey);
        savedAt.value = new Date().toISOString();
        state.value = 'saved';
    }

    /**
     * Resolve a cross-window conflict by dropping this tab's buffered answers.
     * The caller reloads the case so the server draft is shown instead.
     */
    function discardLocal() {
        if (timer) {
            clearTimeout(timer);
        }

        dirty = false;
        clearDraftBuffer(options.storageKey);
        message.value = '';
        state.value = 'saved';
    }

    return {
        state,
        label,
        tone,
        canRetry,
        message,
        online,
        recovered,
        savedAt,
        savedBy,
        revision,
        conflict,
        retry: flush,
        markRecorded,
        discardLocal,
    };
}
