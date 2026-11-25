<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarRange,
    Loader2,
    RotateCcw,
    TriangleAlert,
} from '@lucide/vue';
import { formatWindow } from '@/lib/call-time';
import type { CallTimeFilters, CallTimeWindow } from '@/types/call-time';

/**
 * The window picker shared by the three Call time screens.
 *
 * A manager usually asks for a calendar day or a month, which a rolling day
 * count cannot express — it always ends "now". The picker therefore offers a
 * rolling window, an inclusive `from`/`to` date range, and quick ranges for the
 * two questions that come up most: today and this month. A supplied range wins
 * over the rolling window, exactly as the server resolves it.
 */
const props = withDefaults(
    defineProps<{
        action: string;
        filters: CallTimeFilters;
        windows: number[];
        measured: CallTimeWindow;
        measure?: 'journey' | 'dial';
    }>(),
    { measure: 'journey' },
);

const page = usePage();

const pending = ref(false);

const form = reactive({
    days: props.filters.days,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

// `preserveState` keeps this form mounted across visits, so follow the filters
// the server actually measured instead of letting the fields drift.
watch(
    () => props.filters,
    (filters) => {
        form.days = filters.days;
        form.from = filters.from ?? '';
        form.to = filters.to ?? '';
    },
    { deep: true },
);

const rangeActive = computed(() => form.from !== '' || form.to !== '');

/** Mirrors the select's option labels so the header can state the window. */
const rollingLabel = computed(() =>
    form.days === 1 ? 'Last 24 hours' : `Last ${form.days} days`,
);

const windowChip = computed(() =>
    rangeActive.value ? 'Calendar range' : rollingLabel.value,
);

const filterErrors = computed<string[]>(() => {
    const errors = (page.props.errors ?? {}) as Record<
        string,
        string | undefined
    >;

    return ['from', 'to', 'days']
        .map((field) => errors[field])
        .filter((message): message is string => typeof message === 'string');
});

const caption = computed(() => {
    const subject = props.measure === 'dial' ? 'Dentist dials' : 'Journeys';
    const counted =
        props.measure === 'dial' ? 'their dial start' : 'their start time';

    return props.measured.range
        ? `${subject} counted by ${counted} between ${formatWindow(props.measured)}.`
        : `${subject} counted by ${counted} in the last ${props.measured.days} day${props.measured.days === 1 ? '' : 's'}.`;
});

type PresetKey = 'today' | 'yesterday' | 'week' | 'month' | 'last-month';

const presets: { key: PresetKey; label: string }[] = [
    { key: 'today', label: 'Today' },
    { key: 'yesterday', label: 'Yesterday' },
    { key: 'week', label: 'Last 7 days' },
    { key: 'month', label: 'This month' },
    { key: 'last-month', label: 'Last month' },
];

/** `input[type=date]` needs a local calendar date, not a UTC instant. */
function toDateInput(date: Date): string {
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 10);
}

const todayInput = toDateInput(new Date());
const monthInput = todayInput.slice(0, 7);

/** The full calendar month the applied range covers, so the picker round-trips. */
function selectedMonth(): string {
    const { from, to } = props.filters;

    if (!from || !to || from.slice(8, 10) !== '01') {
        return '';
    }

    const start = new Date(`${from}T00:00:00`);
    const lastDay = toDateInput(
        new Date(start.getFullYear(), start.getMonth() + 1, 0),
    );

    return lastDay === to ? from.slice(0, 7) : '';
}

/** Picking a month fills in its first and last day and searches immediately. */
function applyMonth(event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    if (value === '') {
        return;
    }

    const [year, month] = value.split('-').map(Number);
    form.from = toDateInput(new Date(year, month - 1, 1));
    form.to = toDateInput(new Date(year, month, 0));
    submit();
}

function presetBounds(key: PresetKey): { from: string; to: string } {
    const today = new Date();
    const shift = (days: number): string => {
        const date = new Date(today);
        date.setDate(date.getDate() + days);

        return toDateInput(date);
    };

    switch (key) {
        case 'today':
            return { from: shift(0), to: shift(0) };
        case 'yesterday':
            return { from: shift(-1), to: shift(-1) };
        case 'week':
            return { from: shift(-6), to: shift(0) };
        case 'month':
            return {
                from: toDateInput(
                    new Date(today.getFullYear(), today.getMonth(), 1),
                ),
                to: shift(0),
            };
        case 'last-month':
            return {
                from: toDateInput(
                    new Date(today.getFullYear(), today.getMonth() - 1, 1),
                ),
                to: toDateInput(
                    new Date(today.getFullYear(), today.getMonth(), 0),
                ),
            };
    }
}

function presetIsActive(key: PresetKey): boolean {
    const bounds = presetBounds(key);

    return props.filters.from === bounds.from && props.filters.to === bounds.to;
}

function applyPreset(key: PresetKey): void {
    const bounds = presetBounds(key);
    form.from = bounds.from;
    form.to = bounds.to;
    submit();
}

function submit(): void {
    const query: Record<string, string> = {};

    if (rangeActive.value) {
        if (form.from !== '') {
            query.from = form.from;
        }

        if (form.to !== '') {
            query.to = form.to;
        }
    } else {
        query.days = String(form.days);
    }

    router.get(props.action, query, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => {
            pending.value = true;
        },
        onFinish: () => {
            pending.value = false;
        },
    });
}

/** Drop the range and return to the rolling window the select remembers. */
function useRollingWindow(): void {
    form.from = '';
    form.to = '';
    submit();
}
</script>

<template>
    <section
        class="exp-rise exp-rise-2 overflow-hidden rounded-3xl border bg-card/80 shadow-sm backdrop-blur"
        aria-labelledby="call-time-window-heading"
    >
        <div
            class="flex flex-wrap items-start justify-between gap-3 border-b bg-muted/40 px-5 py-3.5"
        >
            <div class="flex items-start gap-3">
                <span
                    class="mt-0.5 grid size-9 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"
                    aria-hidden="true"
                >
                    <CalendarClock class="size-4" />
                </span>
                <div>
                    <h2
                        id="call-time-window-heading"
                        class="text-sm font-semibold"
                    >
                        Window
                    </h2>
                    <p class="max-w-2xl text-xs text-muted-foreground">
                        {{ caption }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="exp-chip ring-1"
                    :class="
                        rangeActive
                            ? 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/30 dark:text-emerald-300'
                            : 'bg-muted text-muted-foreground ring-border'
                    "
                >
                    <CalendarClock class="size-3" aria-hidden="true" />
                    {{ windowChip }}
                </span>
                <button
                    v-if="rangeActive"
                    type="button"
                    class="exp-btn"
                    @click="useRollingWindow"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" />
                    Rolling window
                </button>
            </div>
        </div>

        <form class="space-y-4 p-5" @submit.prevent="submit">
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="inline-flex items-center gap-1.5 text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                >
                    <CalendarRange class="size-3.5" aria-hidden="true" />
                    Quick range
                </span>
                <button
                    v-for="preset in presets"
                    :key="preset.key"
                    type="button"
                    class="rounded-full border px-3 py-1.5 text-xs font-semibold transition duration-200"
                    :class="
                        presetIsActive(preset.key)
                            ? 'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 shadow-xs dark:text-emerald-300'
                            : 'border-border bg-background text-muted-foreground hover:-translate-y-px hover:border-emerald-500/40 hover:text-foreground'
                    "
                    :aria-pressed="presetIsActive(preset.key)"
                    @click="applyPreset(preset.key)"
                >
                    {{ preset.label }}
                </button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <label class="exp-label">
                    Rolling window
                    <select
                        v-model.number="form.days"
                        class="exp-field disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="rangeActive"
                    >
                        <option
                            v-for="option in windows"
                            :key="option"
                            :value="option"
                        >
                            {{
                                option === 1
                                    ? 'Last 24 hours'
                                    : `Last ${option} days`
                            }}
                        </option>
                    </select>
                </label>
                <label class="exp-label">
                    Month
                    <input
                        type="month"
                        class="exp-field"
                        :value="selectedMonth()"
                        :max="monthInput"
                        @change="applyMonth"
                    />
                </label>
                <label class="exp-label">
                    From
                    <input
                        v-model="form.from"
                        type="date"
                        class="exp-field"
                        :max="form.to || todayInput"
                    />
                </label>
                <label class="exp-label">
                    To
                    <input
                        v-model="form.to"
                        type="date"
                        class="exp-field"
                        :min="form.from || undefined"
                        :max="todayInput"
                    />
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    class="exp-btn exp-btn-primary disabled:cursor-not-allowed disabled:opacity-70"
                    :disabled="pending"
                >
                    <Loader2
                        v-if="pending"
                        class="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    <CalendarClock v-else class="size-4" aria-hidden="true" />
                    {{ pending ? 'Measuring…' : 'Apply window' }}
                </button>
                <p class="text-xs text-muted-foreground">
                    A calendar range wins over the rolling window, and either
                    end may be left blank. Picking a month fills in its first
                    and last day and searches immediately.
                </p>
            </div>

            <p
                v-for="message in filterErrors"
                :key="message"
                role="alert"
                class="flex items-start gap-2 rounded-xl bg-rose-500/10 px-3 py-2 text-xs font-medium text-rose-700 ring-1 ring-rose-500/30 dark:text-rose-300"
            >
                <TriangleAlert
                    class="mt-px size-3.5 shrink-0"
                    aria-hidden="true"
                />
                {{ message }}
            </p>
        </form>
    </section>
</template>
