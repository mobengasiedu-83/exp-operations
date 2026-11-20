export type LiveFilters = {
    from: string;
    to: string;
    agent: string;
    destination: string;
    state: string;
};

export type LiveFreshness = {
    state: string;
    heartbeat_age_seconds: number | null;
    last_event_age_seconds: number | null;
    stale_after_seconds: number;
};

export type LiveAlert = {
    id: number;
    rule: string | null;
    name: string | null;
    severity: string;
    status: string;
    subject: string | null;
    details: Record<string, unknown> | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
    acknowledged_at: string | null;
};

export type LiveActiveCall = {
    id: number;
    started_at: string | null;
    duration_seconds: number | null;
    stage: string;
    agent_channel: string | null;
    destination_channel: string | null;
    timing_quality: string;
    is_exp: boolean;
    exceptions: number;
};

export type LiveTransfer = {
    id: number;
    interaction_id: number;
    channel: string | null;
    dial_status: string | null;
    dial_started_at: string | null;
    ringing_at: string | null;
    answered_at: string | null;
    ended_at: string | null;
    ringing_for_seconds: number | null;
    dial_to_answer_seconds: number | null;
    active: boolean;
};

export type LiveSnapshot = {
    generated_at: string;
    window: { from: string; to: string; from_iso: string; to_iso: string };
    connection: {
        id: number;
        name: string;
        status: string;
        last_event_at: string | null;
        last_heartbeat_at: string | null;
        last_error: string | null;
    } | null;
    freshness: LiveFreshness;
    agent_presence: {
        state: string;
        extension: string;
        inferred: boolean;
        as_of: string;
    };
    kpis: Record<string, number>;
    active_calls: LiveActiveCall[];
    transfers: LiveTransfer[];
    alerts: LiveAlert[];
    alert_counts: { open: number; acknowledged: number };
    options: { agents: string[]; destinations: string[] };
};
