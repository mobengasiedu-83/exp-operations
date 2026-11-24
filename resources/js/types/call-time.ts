export type CallTimeWindow = {
    days: number;
    from: string;
    to: string;
};

export type CallTimeTotals = {
    total_seconds: number;
    wait_seconds: number;
    agent_seconds: number;
    dentist_seconds: number;
    consultation_seconds: number;
    handover_seconds: number;
    hold_seconds: number;
    ringing_seconds: number;
    dial_to_answer_seconds: number;
};

export type CallTimeOverview = {
    window: CallTimeWindow;
    calls: {
        total: number;
        active: number;
        completed: number;
        answered: number;
        transferred: number;
        abandoned: number;
    };
    totals: CallTimeTotals;
    averages: {
        total_seconds: number | null;
        wait_seconds: number | null;
        agent_seconds: number | null;
        dentist_seconds: number | null;
        consultation_seconds: number | null;
        ringing_seconds: number | null;
    };
    samples: { wait: number; agent: number; dentist: number };
    daily: {
        date: string;
        calls: number;
        answered: number;
        total_seconds: number;
        wait_seconds: number;
        agent_seconds: number;
        dentist_seconds: number;
    }[];
};

export type CallTimeAgentRow = {
    key: string;
    extension: string | null;
    calls: number;
    answered: number;
    talk_seconds: number;
    talk_samples: number;
    wait_seconds: number;
    dentist_seconds: number;
    longest_talk_seconds: number | null;
    avg_talk_seconds: number | null;
    avg_wait_seconds: number | null;
    last_call_at: string | null;
};

export type CallTimeAgents = {
    window: CallTimeWindow;
    rows: CallTimeAgentRow[];
    summary: {
        agents: number;
        calls: number;
        answered: number;
        talk_seconds: number;
        unattributed_calls: number;
    };
};

export type CallTimeDentistRow = {
    key: string;
    dentist_id: number | null;
    name: string | null;
    matcher: 'directory' | 'unmatched';
    attempts: number;
    answered: number;
    connected_seconds: number;
    consultation_seconds: number;
    handover_seconds: number;
    answer_rate: number | null;
    avg_connected_seconds: number | null;
    avg_dial_to_answer_seconds: number | null;
    avg_ringing_seconds: number | null;
    last_attempt_at: string | null;
};

export type CallTimeDentists = {
    window: CallTimeWindow;
    rows: CallTimeDentistRow[];
    summary: {
        attempts: number;
        answered: number;
        matched_attempts: number;
        unmatched_attempts: number;
        match_rate: number | null;
        answer_rate: number | null;
        connected_seconds: number;
        consultation_seconds: number;
    };
};
