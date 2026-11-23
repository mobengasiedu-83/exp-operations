# EXP — Phased Implementation Roadmap

**Agent Call & Dentist Transfer Console**  
Pepsodent Talk to a Dentist | Ghana  
Version 1.0 | 19 November 2026

> **20 November 2026 audit:** See [remaining implementation and rollout](EXP_Remaining_Implementation.md) for current status. The Viewer role and guide menu now exist in `GuideAccessSeeder`; older statements below saying Viewer is absent are historical. Live PBX, browser-transfer and production acceptance remain open.

## Objective
Deliver a focused application where an agent sees an incoming call, answers it, completes already-configured screening questions, selects a dentist, privately briefs the dentist and transfers the caller. Call History must show **the caller and the dentist actually connected**; clicking a call must reveal all answers, notes, attempts, timestamps and durations.

## Delivery strategy
Reuse the existing Laravel 13 / Vue / Inertia / MySQL / Redis application and read-only VitalPBX AMI correlation. Preserve the existing Jitsi/manual attended transfer while the new browser-controlled call functions are proven in a lab. Do not confuse implemented application code with verified live PBX behaviour. These are sequential delivery gates, not claims that the phases are already accepted.

## Implementation status against this roadmap (verified 19 November 2026)

This section records what the repository actually contains. It was checked against `routes/web.php`, `app/Http/Controllers`, `app/Services`, `app/Models`, `database/seeders` and the feature tests — not against the source Word documents. Verification command: `php artisan test --compact` → **366 tests, 364 passed, 2 skipped, 2,953 assertions**. Both skips are the registration tests in `tests/Feature/Auth/RegistrationTest.php`, because registration is not in the `config/fortify.php` feature list; the per-file figures below come from running each suite directly. Phase 4 was reviewed the same way and adds 79 passing tests across seven suites, Phase 5 adds 61 (`CallControlTest` 42, `TransferTrackingTest` 19) and Phase 6 adds 8 (`ReportingTest`); all three are recorded in their own sections below, and the total above already includes them.

| Phase | Application code in this repository | Passing suites | Open item |
|---|---|---|---|
| 1 — Foundation and simplification | Permission-filtered shell (`PrimaryNavigation`), `Agent`/`Supervisor`/`Administrator` roles, dentist directory with normalised numbers and non-destructive removal, versioned questionnaire with a single active version | `PrimaryNavigationTest` (11), `PhaseFoundationTest` (6), `DentistDirectoryTest` (20), `DentistDirectorySeederTest` (2) | **`Viewer` is not seeded**; the three simplified roles are additive to the retained legacy roles (see Phase 1 notes) |
| 2 — Agent workspace and answer capture | Incoming/active call card, interaction association, autosave drafts, durable answer snapshots, consent history, safety floors, offline recovery | `AgentWorkspaceTest` (51), `FollowUpTest` (16) | Live-handset confirmation of the call card belongs to the Phase 4 gate |
| 3 — Call history and comprehensive call detail | Filtered history, full detail with attempts/timeline/durations, masking, audited export | `CallJourneyAccessTest` (12) | PBX-verified timing and attribution belong to the Phase 4 gate |
| 4 — Live PBX event tracking and reconciliation | Read-only AMI listener with heartbeat/reconnect and retention, Uniqueid/Linkedid correlation with exception states, projected timings that stay null when unproven, CDR/CEL import and per-call reconciliation, coverage-gap invalidation and alerting, plus diagnostics screens. **Code complete, live gate open** | `AmiListenerTest` (13), `CallCorrelationTest` (24), `CallReconciliationTest` (11), `TelemetryCoverageTest` (7), `CallRecordImportTest` (8), `LiveOperationsTest` (9), `PbxIntegrationTest` (7) | Real controlled-call acceptance matrix on the PBX |
| 5 — Browser call controls | Verified call-control state machine (answer, hold/resume, dial, cancel, private briefing, complete transfer, agent release), separate HTTPS control gateway with its own identity, idempotent command log with one unresolved command per call, ownership and safety-screen enforcement, Jitsi fallback with auditable operator evidence. **Code complete, lab gate open** | `CallControlTest` (42), `TransferTrackingTest` (19) | Lab warm transfer through a real control service (Local-channel, masquerade, hangup/recovery, caller continuity) |
| 6 — Reports, hardening, pilot | Operational report catalogue (10 definitions, 43 attributable KPIs including dentist ringing and verified caller–dentist bridge durations) with per-KPI evidence, saved run snapshots and audited CSV export, gated by `view reports` / `view executive reports` / `export reports`. **Reporting code complete; hardening and pilot gates open** | `ReportingTest` (8) | Ubuntu deployment, TLS/MFA/backup decisions, privacy and retention approvals, `Viewer` role, signed-off pilot and restore drill |

"Implemented" here means the application code, server-side authorisation and automated tests exist and pass. It is not a claim that live PBX behaviour has been accepted: every acceptance gate that needs a real call stays open and is carried into the Phase 4 lab gate.

## Phase overview

| Phase | Name | Main outcome |
|---|---|---|
| 1 | Foundation and simplification | Four-menu application shell, roles, dentist directory and versioned questionnaire |
| 2 | Agent workspace and answer capture | Incoming/active call association, questionnaire autosave and safe agent workflow |
| 3 | Call history and comprehensive call detail | Searchable caller-to-dentist history and one-click full evidence view |
| 4 | Live PBX event tracking and reconciliation | Reliable near-real-time states and trustworthy transfer timing |
| 5 | Browser call controls and attended transfer | Answer, hold, dial dentist, private briefing, complete/cancel transfer from EXP |
| 6 | Operational reports, hardening and Ubuntu pilot | Basic management visibility, secure deployment and signed-off production readiness |

## Workflow and core record

`Incoming → Agent answer → The ten configured questions → Select dentist → Caller on hold → Dentist dial → Private briefing → Complete transfer → Caller–dentist bridge → Call detail`.

One call has one canonical interaction ID, one agent, one saved questionnaire version, many answers, zero or more transfer attempts, and **at most one final verified connected dentist**. A failed attempt never overwrites previous attempts. A PBX network answer by itself does not establish that a human dentist accepted.

### Call History columns
`Call ID | Date/time | Caller name/number | Agent | Dentist transferred to | Outcome | Duration`

### Call Detail sections
1. Caller and inbound line, call timestamps, agent and status.
2. Exact questionnaire version, question text, each saved answer, unanswered/unknown state, agent and save time.
3. Final verified dentist (or **Unverified** bridge evidence, or **No connected dentist**) and connected mobile destination snapshot.
4. All dentist attempts, in chronological order, with result and retry lineage.
5. PBX event timeline and measured durations, distinguishing unknown/missing evidence.
6. Consent, safety escalation, factual notes, audit metadata and restricted export controls.

## Phase 1 — Foundation and simplification

**Deliverable:** Four-menu application shell, roles, dentist directory and versioned questionnaire.

### Work packages

1. **Audit existing code.** Inventory Laravel 13/Vue/Inertia, migrations, existing AMI listener, call correlation, dentist directory and screening screens. Preserve data and existing telephony routing; hide unrelated modules, do not drop tables.
2. **Navigation and permissions.** Implement Agent Workspace, Call History, Dentists, Administration. Roles: Agent (own calls), Supervisor (cross-agent monitoring), Administrator (configuration), Viewer (read-only access to permitted call history and reports). Protect every endpoint server-side. *Implemented for Agent, Supervisor and Administrator. The **Viewer role is still missing** — see the role notes under Phase 1 implementation notes.*
3. **Dentist records.** CRUD for name, configured E.164 mobile number, active flag, manually recorded availability; prevent selection of inactive/unconfigured numbers; never infer mobile availability from AMI.
4. **Questionnaire administration.** Versioned question definitions, ordering, types, required/conditional rules and explicit unanswered/unknown states. The ten supplied screening questions are already approved and are active by default; no question approval or activation workflow is required. Administrator edits take effect directly with version snapshots and audit logging.
5. **Active questionnaire flag.** `screening_protocols.is_active` (indexed, default false) marks the one version new calls must use. Saving a new version activates it and retires the previous one in a single transaction; activation is versioned and audited, and retiring the active version is blocked. Consent captures require the active version, while autosaved answers and recorded screenings may also continue on the version already attached to a granted consent (see Phase 2) and historical screenings and closures keep working against their own recorded version. The retained `clinical_approved_*` and `privacy_approved_*` columns are historical only and are no longer written.
6. **Local setup.** Confirm .env, MySQL, Redis/Horizon, queue/scheduler and existing tests; run migrations additively with backup and rollback plan.

### Acceptance gate

- [x] Agent logs in and sees four menus only *(Dashboard, Agent Workspace, Call History, Dentists — `PrimaryNavigationTest` asserts exactly four entries and no Administration entry; Dashboard is the always-available landing page, so an Administrator sees five)*
- [x] Administrator can maintain dentist list and active questionnaire version *(dentist create/update/deactivate, number add/retire and the questionnaire editor are gated by `edit dentists` and `manage screening protocols`)*
- [x] No existing call data or PBX route is lost *(dentist and number deletion deactivate or retire instead of deleting; the questionnaire migrations only add `is_active` and the answer prompt snapshot; retained tables, telemetry routes and legacy roles are untouched)*

**Not yet met:** the four-role set. `Viewer` has no role, no permission set and no seeder entry, so read-only access to permitted history and reports cannot be granted today (see the role notes below).

### Implementation notes (2026-11-19)

- Migration `2026_11_19_240000_add_active_questionnaire_flag` adds `is_active`, promotes the latest previously approved row and is reversible.
- `ScreeningProtocol::activate()` enforces the single-active invariant inside a transaction; `scopeActive()` and `isActive()` expose it to controllers and services.
- `approve screening protocols` and `approve screening privacy` permissions were removed from seeders, navigation and tests; `manage screening protocols` is the only questionnaire permission.
- `Workspace/Protocols.vue` creates-and-activates versions, shows the active badge and offers explicit Activate/Retire actions instead of clinical/privacy approval forms.
- `ScreeningProtocolSeeder` seeds the ten supplied questions as the active version (`exp-2026-11-19-v1`) with the blueprint privacy notice, P1/P2/P3/S/Review guidance and service FAQ answer.
- `AgentWorkspaceTest`, `FollowUpTest` and `PrimaryNavigationTest` cover activation, retired-version reuse, consent-version matching and the reduced permission set; the `control calls` permission itself is exercised in `CallControlTest` (see Phase 5).
- **Navigation shell.** `PrimaryNavigation::forUser()` derives the menu from permissions, not role names: Dashboard is always present, Agent Workspace needs `view cases`, Call History needs `view call history` or `view pbx`, Dentists needs `view dentists`, and Administration appears when any configuration view permission is held. An account with no permissions sees the dashboard only.
- **Roles as actually seeded.** `DatabaseSeeder` defines `Agent` (`view cases`, `create cases`, `edit cases`, `control calls`, `view call history`, `view dentists`), `Supervisor` (the agent set plus `view all cases`, `review cases`, `view audit`) and `Administrator` (every permission). Every account keeps whatever legacy roles it already had (`Super Administrator`, `Platform Manager`, `Lead Dentist / Clinical Supervisor`, `Dental Assistant`, `Dentist`, `QA Reviewer`, `Executive / Management`), so the four-role model is additive rather than a replacement. The legacy roles were deliberately not deleted, which is why the application still contains more than four role names.
- **Viewer gap (open).** There is no `Viewer` role and no permission that grants read-only case content: `view call history` alone restricts the list to cases assigned to the account, `view pbx` widens it to all calls but still withholds case content, and full detail needs `view cases`, which also opens Agent Workspace. Adding Viewer therefore requires a dedicated read-only case permission plus the blueprint's access-scoping rule ("scoped by assigned access"), so it is tracked with the Phase 6 role-permission and privacy review instead of being half-implemented here.
- **Supervisor breadth (decision needed).** Supervisor holds `edit cases`, `review cases` and `control calls` on top of monitoring, so it can edit answers and drive its own assigned calls. That is broader than "cross-agent monitoring" and should either be tightened or explicitly accepted during the Phase 6 permission review.
- **Dentist directory.** `DentistController` normalises numbers through `GhanaPhoneNumber::normalize()` to `+233XXXXXXXXX`, keeps the typed value in `original`, and rejects duplicates across the whole directory. Deleting a dentist sets `active = false` and clears the primary flag; deleting a number sets `status = 'retired'`. Transfer selection (`DentistDirectory::selectable()`) therefore only ever offers active dentists with approved numbers, and nothing is hard-deleted. Availability comes from manually entered rosters and overrides; observed mobile legs are used for call activity only and never to infer whether a dentist is free.
- **Questionnaire versioning detail.** `screening-protocols` exposes only index, store (create-and-activate), activate and retire — there is no update route, so a saved version is immutable and history always keeps its own wording. A positive answer may only be routed to P1 or P2, and a retired version cannot be reactivated (`abort_if` in `ScreeningProtocolController::activate()`).

### Dependencies and hand-off

Existing repository, database backup, current questionnaire and dentist numbers; sign off navigation and permissions.

## Phase 2 — Agent workspace and answer capture

**Deliverable:** Incoming/active call association, questionnaire autosave and safe agent workflow.

### Work packages

1. **Incoming call UI.** Display inbound caller number (or Unknown), inbound DID, agent extension, arrival time, ringing and connection freshness. Existing Jitsi remains telephony control in initial mode.
2. **Interaction association.** Bind one PBX interaction to one agent case; require confirmation when identity ambiguous; reject duplicate cases and concurrent conflicting edits.
3. **Form capture.** Show the ten configured questions on the active call page; autosave answer, status, question-version snapshot, author and timestamp. Include caller preferred name, approved contact fields, factual notes and consent state.
4. **Safety.** Do not bypass defined red-flag/emergency instructions; preserve unknown/unfinished answers. Do not make transfer mandatory for emergency handling.
5. **Resilience.** Recover draft after browser refresh; show unsaved/offline state; do not mislabel PBX connection if AMI is stale.

### Acceptance gate

- [x] A controlled call appears on the correct agent screen *(proven against simulated AMI evidence in `AgentWorkspaceTest`; one live handset confirmation remains part of the Phase 4 lab gate)*
- [x] Agent records and reopens exact question/answer pairs *(recording writes `screenings` plus one `screening_answers` row per configured question with the prompt snapshot, status, version, author and save time; reopening reads those snapshots, never the live questionnaire)*
- [x] Refresh does not lose confirmed answers or duplicate cases *(the unique `cases.interaction_id` index makes reopening return the same case, and `useScreeningDraft` restores the `sessionStorage` buffer with an explicit recovery warning)*

### Implementation notes (2026-11-19)

- **Incoming caller card.** `AgentCallContext` serves the masked caller number (or Unknown), inbound DID, agent extension, arrival time, ring timer, stage and freshness to `Workspace/Index` (active calls, recent journeys, ambiguity banner) and `Workspace/Show` (case call card). Both screens poll only the `callContext` prop every five seconds, so the questionnaire and draft state are never re-fetched mid-call.
- **Freshness.** `LiveOperationsSnapshot::connectionFreshness()` reports live/degraded/stale/offline from the AMI listener heartbeat and last event. Any non-live state raises a visible warning that the screen is not proof that no call is waiting and that Jitsi remains the telephony control.
- **Interaction association.** `workspace.store` requires verified EXP evidence plus an observed Assistant leg, locks the interaction row, and relies on the unique `cases.interaction_id` index: reopening the same call returns the existing case instead of creating a duplicate. Ambiguity (`multiple_active_calls`, `correlation_exceptions`, `possible_missing_hangup`) must be acknowledged explicitly, and the reasons are written to the activity log.
- **Concurrent edits.** Case changes carry a `revision`; a stale revision is rejected. Draft autosave carries its own `revision`; a stale save returns HTTP 409 and the screen offers "discard this window's unsaved answers and reload" instead of overwriting the other window.
- **Form capture.** Questions come from the case's granted-consent version when one exists, otherwise from the active version. Conditional questions appear only when their trigger matches. `screening_drafts` autosaves every answer with `answer_status` (`answered`, `unanswered`, `unknown`, `skipped`), `question_version`, `recorded_by` and `saved_at`; the browser debounces saves by 1.2 s.
- **Promotion to durable answers.** "Record safety screen" writes an immutable `screenings` row plus one `screening_answers` row per configured question, including a `question_prompt` text snapshot (migration `2026_11_19_250000_add_question_snapshot_to_screening_answers`), then deletes the draft. Reopening the case shows the exact question/answer pairs with status, version, author and save time.
- **Caller record.** Consent status (granted/declined/unable/withdrawn) with callback permission, encrypted preferred name, callback number, guardian and concern fields, Ghana number normalisation, and factual notes. Profile capture and notes are blocked without granted consent; withdrawal appends a new consent record and preserves the earlier history.
- **Safety path.** P1/P2 guidance from the questionnaire is pinned above the workspace as soon as an answer triggers it. P1 escalation never requires consent, profile details, a completed screen or dentist availability, and no transfer is required to close an emergency. Priority floors cannot be lowered without `review cases` and a recorded reassessment reason; unknown or unfinished answers keep the case in clinical review.
- **Mid-call version change.** `CaseWorkflow::captureProtocol()` accepts the active version or the version attached to the caller's granted consent, so an Administrator activating a replacement version mid-call does not strand autosaved answers. When the latest consent is withdrawn or declined, the case page falls back to the active version so consent can be recorded again.
- **Offline recovery.** `useScreeningDraft` buffers answers in `sessionStorage`, restores a newer local buffer after refresh with an explicit "recovered" warning, warns before leaving with unsaved answers, retries when the connection returns, and clears the buffer once the server confirms the save.
- **Handover evidence.** Approved, active dentist numbers (masked, with manually recorded availability) are listed on the case page and in the SBAR handover text; dialling and transfer stay in Jitsi by default, and the Phase 5 browser controls are implemented but disabled until a control service is lab-approved.
- **Tests.** `AgentWorkspaceTest` covers simulated call evidence and stale telemetry, ambiguity acknowledgement, duplicate-case reopening, per-answer status/author/version snapshots, autosave conflicts, mid-call activation, withdrawn-notice fallback, emergency bypass, priority floors and permission scoping; `FollowUpTest` keeps follow-up capture aligned and the Jitsi fallback contract is asserted in `CallControlTest` (Phase 5).

### Dependencies and hand-off

Phase 1 and configured questionnaire/privacy/safety wording; hand over durable call-linked answer records.

## Phase 3 — Call history and comprehensive call detail

**Deliverable:** Searchable caller-to-dentist history and one-click full evidence view.

### Work packages

1. **History table.** Columns: call ID/time, caller name/number, agent, final verified dentist, transfer outcome, duration. Filters for caller, agent, dentist, date, status and call ID.
2. **Call detail.** Click any row to open caller metadata, agent, questionnaire version and every answer including unanswered/unknown, consent state, notes, final dentist and outcome.
3. **Attempts.** List all dentists dialled, destination snapshot, attempt order, busy/no answer/voicemail/cancel/connected status; distinguish attempted from actually connected dentist.
4. **Timeline.** Display inbound ring, agent answer, hold, dentist dial/ring/answer, private briefing, bridge, agent exit, dentist hangup and confidence/source of each event.
5. **Privacy.** Role-scoped access, encrypted sensitive fields, masked numbers for unauthorised viewers, audited detail/export access.

### Acceptance gate

- [x] First dentist no answer then second connects: list shows second; detail shows both *(proven against correlated attempt evidence in `CallJourneyAccessTest`; the live PBX timing proof stays in the Phase 4 lab gate)*
- [x] Clicking any call displays exact historical answers and timestamps *(prompt, status, version, author and save time are read from the answer snapshot, not the live questionnaire; asserted in `CallJourneyAccessTest`)*
- [x] No verified bridge displays No connected dentist, not a false success *(a network answer or unconfirmed consultation bridge is shown as "Unverified", never as a connected dentist — `TransferAttempt::isVerifiedConnection()` / `hasUnverifiedBridgeEvidence()`)*

**Caveat:** these checks are met for accounts with `view cases` (Agent, Supervisor, Administrator) and for `view pbx` accounts that see the masked list without case content. The blueprint's read-only `Viewer` acceptance checks cannot be run yet because the role does not exist (see Phase 1 notes).

### Implementation notes (2026-11-19)

- **History table.** `CallJourneyController::index()` (`calls.index`) renders `Calls/Index.vue` with the blueprint columns: call ID and start time, masked caller number and name, agent, final verified dentist, connection state, attempts, answered questions, outcome, duration and call state. Default order is newest first and filters cover scope (all calls / my calls), caller name, call ID, agent, dentist, date range, call state, connection state, phone number and correlation ID.
- **Attribution.** `CallHistoryRecord::summary()` derives a three-state connection: `verified` (explicit dentist acceptance plus a confirmed consultation bridge), `unverified` (bridge/transfer evidence without human acceptance, or a voicemail bridge) and `none`. `TransferAttempt::isVerifiedConnection()` / `hasUnverifiedBridgeEvidence()` hold the rule and mirrored query scopes power the `connection` filter. `connected_dentist` stays `No connected dentist` unless verified; `unverified_dentist`, `attempted_dentists`, `attempts_count`, `answered_questions` and `question_count` accompany every row.
- **Call detail.** `Calls/Show.vue` follows the A–G contract: caller and call metadata, dentist and transfer outcome, questionnaire answers (prompt snapshot, saved answer, status including unanswered/unknown, version, save time and author), attempts in order with dial/ring/answer/acceptance/bridge timestamps, the derived timeline, measured durations with "Not available" where evidence is missing, and notes, consent history and access audit. Drafts are labelled unconfirmed and excluded from the call record. Raw correlation evidence (legs, metrics, bridges, events, diagnostics) stays available behind a disclosure so PBX specialists keep full fidelity.
- **Filters survive navigation.** The detail link carries the active query string and the detail page's back link restores it, so supervisors do not lose a filtered list.
- **Privacy and audit.** `visibleInHistory()` scopes agents to their own cases; case content (caller name, agent, notes, answers) requires case permission and is withheld from telemetry-only viewers. Caller IDs are never stored in full, so every screen and export shows the masked number only. Opening a detail page writes `Call detail viewed` to the `call-history` activity log and the page displays that audit trail. Until the `Viewer` role exists, read-only history is granted through permissions instead of a role: `view call history` alone restricts the list to cases assigned to the account, cross-agent scope needs `view pbx` or `view all cases`, and case content always needs case permission — so no account can hold cross-agent read-only case content (see Phase 1 notes).
- **Export.** New `export call history` permission (granted by default to Administrator and Super Administrator only) gates `calls.export`. The CSV follows the current filters, contains no free-text answers or notes, and each export writes `Call history exported` to the audit log. Spreadsheet formula prefixes are neutralised.
- **Durations.** `CallHistoryRecord::durations()` reports inbound wait, agent talk, hold/transfer, dial-to-answer, dentist ringing, private briefing, dentist connected, verified bridge and total time; anything the PBX did not measure stays null and renders as "Not available".
- **Tests.** `CallJourneyAccessTest` covers permission scoping, masked-number search, verified versus unverified attribution with two attempts, the connection filters, export permission/masking/audit, durable question snapshots, durations and the access audit trail.

### Dependencies and hand-off

Phase 2 data and existing call correlation; hand over a complete supervisor call record. Phase 4 must replace operator-confirmed bridge durations with PBX-verified timings and leave unsupported metrics null.

## Phase 4 — Live PBX event tracking and reconciliation

**Deliverable:** Reliable near-real-time states and trustworthy transfer timing.

**Status (2026-11-19): code complete, live gate open.** Every work package is implemented and covered by 79 passing tests across seven suites (`AmiListenerTest` 13, `CallCorrelationTest` 24, `CallReconciliationTest` 11, `TelemetryCoverageTest` 7, `CallRecordImportTest` 8, `LiveOperationsTest` 9, `PbxIntegrationTest` 7). No controlled call has been run against the live VitalPBX yet, so the first acceptance item — real timelines inside the agreed tolerance — cannot be signed off from this repository.

### Work packages

1. **Listener health.** Reuse persistent read-only AMI listener, heartbeat/reconnect, single-process lock, sanitised event persistence and 2–5 second dashboard polling. *(Implemented: `exp:ami-listen` holds an exclusive `flock` so a second process exits before touching connection state, traps `SIGTERM`/`SIGINT`, sends `Ping` every 25 s with `ActionID=exp-heartbeat` and fails the connection after a 10 s timeout, retries with exponential backoff capped at 60 s, and redacts AMI credentials from every stored error. `pbx:connect`, `pbx:disconnect` and `pbx:reconnect` drive a persisted `listener_enabled` flag and `reconnect_generation` counter so a running process reconnects without restarting. `AmiClient` uses non-blocking sockets, keeps partial lines across read timeouts and rejects call-control actions and header injection. Events are persisted redacted to `ami_events` and `structured_listener_logs`, capped by `exp:prune-telemetry` (7 days / 100 000 rows by default). Dashboards poll `EXP_LIVE_REFRESH_SECONDS` (default 3, clamped to the 2–5 s blueprint band).)*
2. **Correlation.** Map Asterisk Uniqueid/Linkedid, caller/agent/dentist legs and attended transfer into one interaction, with explicit exception states. *(Implemented: `CallCorrelator` + `AmiEventNormalizer` resolve Uniqueid, Linkedid and every transfer counterpart header into one interaction, merge duplicate interactions (re-pointing legs, events and transfer attempts), and record explicit exceptions — `missing_identity`, `failed_transfer_crosses_interactions`, `multiple_verified_cases_require_review`, `event_processing_failed` — that are readable at `diagnostics/correlation-exceptions` and never silently dropped.)*
3. **Timing.** Separate outbound dial-to-answer, actual ringing when signalled, mobile connected time, agent–dentist private briefing and verified caller–dentist bridge duration. Unknown remains null. *(Implemented in `CallTimelineProjector`: ringing is only recorded when the PBX signalled Ring/Ringing or an answer arrived, mobile connected time comes from the answered-to-hangup interval of that leg, private briefing and consultation come from bridge membership per stage, and a missing or ambiguous signal leaves the metric null with a projection exception instead of an invented number.)*
4. **Reconciliation.** Compare AMI against available CDR/CEL exports; mark incomplete, stale, mismatched or unmatched evidence; never equate network ANSWER with human acceptance. *(Implemented: `exp:import-call-records` ingests JSON Lines CDR/CEL with a mandatory IANA source timezone, converts to UTC, validates schema, durations and clock sanity, and rolls back the whole export on failure with replay fingerprints preventing duplicates. `exp:reconcile-calls --pending` runs every minute and compares leg start/answer/end, CDR disposition and connected duration, and CEL START/ANSWER/HANGUP/bridge timestamps against `EXP_PBX_RECONCILIATION_TOLERANCE_SECONDS` (default 2 s, capped at 300 s), writing `matched`, `discrepancy`, `insufficient` or `pending` plus per-code exceptions (`missing_cdr`, `unmatched_cdr_leg`, `cdr_answer_state_mismatch`, `cdr_connected_duration_mismatch`, `cel_answer_mismatch`, `incomplete_bridge_timeline`, …). A network `ANSWER` never becomes human acceptance: legs keep `acceptance_status = unconfirmed` and only `TransferAttempt::isVerifiedConnection()` can mark a bridge as verified.)*
5. **Real tests.** Test successful transfer, two dentist attempts, busy, no answer, voicemail, dropped AMI, reconnect, late events and agent exit. *(Implemented as automated coverage over simulated AMI frames, imported CDR/CEL fixtures and listener fakes — `AmiListenerTest`, `CallCorrelationTest`, `CallReconciliationTest`, `TelemetryCoverageTest`, `CallRecordImportTest`. These prove the contracts and failure modes; they are not a substitute for the live matrix below.)*

### Acceptance gate

- [ ] Controlled PBX test calls match timelines within agreed tolerance *(**open** — requires the live controlled-call matrix. The application side is ready: configurable tolerance, deterministic reprojection via `exp:correlate-calls --rebuild`, `matched`/`discrepancy`/`insufficient`/`pending` statuses, and a manual reconcile action on `Calls/Show` gated by `view pbx` + `edit settings`.)*
- [x] Agent exit does not erase ongoing dentist consultation *(asserted in `TelemetryCoverageTest` — "agent exit preserves an ongoing consultation until the caller and dentist hang up": bridge stages are rebuilt from `BridgeEnter`/`BridgeLeave` membership rather than the agent leg, and the interaction stays `active` until every leg has a hangup)*
- [x] Offline/stale telemetry visibly identified *(`LiveOperationsSnapshot::connectionFreshness()` reports `live`/`stale`/`offline`/`unconfigured` from listener status and heartbeat age (90 s threshold); Agent Workspace and `Calls/Show` raise explicit banners, `Calls/Show` lists coverage gaps and unprocessed source events, the `ami_stale` alert rule opens an operator alert, and `TelemetryCoverageTest` asserts the stale and historical-gap views)*

**Not yet met:** the first item only. It needs the real PBX matrix (successful transfer, two dentist attempts, busy, no answer, voicemail, dropped AMI, reconnect, late events, agent exit) with clocks confirmed by NTP, so Phase 4 stays "code complete, gate open" until that lab session is executed and recorded.

### Implementation notes (2026-11-19)

- **Listener lifecycle.** One process per host (`flock` on `EXP_AMI_LISTENER_LOCK_PATH`), credential pre-flight before connecting, heartbeat every 25 s, reconnect backoff 2→60 s (doubling, then capped), and clean shutdown that marks the connection and integration health as disconnected. `ConnectionHealth` keeps reconnect attempts and event lag for the PBX screens.
- **Evidence handling.** Raw caller IDs are never stored: `AmiEventNormalizer` keeps a masked reference plus a keyed fingerprint, `PbxTelemetry::redact()` strips credentials, dial strings, caller IDs and channel numbers, and retention is bounded by both age and row count so a burst cannot exhaust the database.
- **Correlation merges.** Aliases accumulate across Uniqueid/Linkedid so a call that starts on the IVR, crosses a Local channel and ends in an attended transfer resolves to one interaction; failed attended transfers that would touch two journeys are quarantined instead of merged, and two verified cases on one interaction block automatic merging for supervisor review.
- **Projection rules.** Metrics are derived only from observed evidence: `billsec` is never treated as consultation, held time is excluded from caller–dentist bridge time, a three-party bridge is flagged `ambiguous` and excluded from private briefings, and an open bridge interval keeps its duration null until the PBX closes it.
- **Coverage gaps beat reconciliation.** `PbxTelemetryGap` opens on disconnect and closes on reconnect; every call overlapping a gap loses its derived durations (leg metrics, bridge durations, consultation intervals), gains a `telemetry_gap` exception and returns to `pending` reconciliation. A later matching CDR/CEL import therefore cannot manufacture a "matched" call out of a period the listener did not observe.
- **Late and out-of-order events.** Ingestion is idempotent (an event fingerprint keyed to the raw AMI row whenever the PBX supplied no timestamp, so two events in the same clock second are still distinct), replaying a batch reproduces the same projection, and `exp:correlate-calls --rebuild` reprojects durable events after a code or configuration fix without touching raw evidence.
- **Alerting.** `exp:evaluate-alerts` runs every minute against `alert_rules` (`ami_stale` at 90 s heartbeat age, `long_ringing` at 45 s without answer, `failed_transfer` for completed journeys with only failed mobile attempts), resolves alerts whose condition cleared, and reopens acknowledged alerts when the condition returns.
- **Operator surfaces.** Live Operations shows freshness and journey KPIs; `Calls/Show` shows AMI freshness, coverage gaps, unprocessed event count and a manual reconcile action; `Calls/Exceptions.vue` filters ingestion/projection/reconciliation exceptions; the Administration area keeps AMI health, CDR/CEL imports, connection health and integration logs.

### Dependencies and hand-off

Phase 3 detail contract plus, from the PBX owner: a read-only AMI account (never the write account reserved for Phase 5), inbound DID/context and screening-extension confirmation, one real CDR/CEL export with its source timezone, NTP-synced PBX/browser clocks, and the controlled-call matrix above. Hand-off is the verified event and timing set with reconciliation status per call; metrics the PBX does not measure stay null rather than estimated.

## Phase 5 — Browser call controls and attended transfer

**Deliverable:** Answer, hold, dial dentist, private briefing, complete/cancel transfer from EXP.

**Status (2026-11-19): code complete, lab gate open.** Every work package is implemented and covered by 61 passing tests (`CallControlTest` 42, `TransferTrackingTest` 19). The controls are deliberately inert on a stock deployment: `PbxCallControlGateway::configured()` requires `EXP_CALL_CONTROL_ENABLED`, `EXP_CALL_CONTROL_LAB_APPROVED`, an HTTPS URL without embedded credentials, a token and a control identity, so Jitsi stays the telephony path until a control service is approved. No warm transfer has been driven through a live control service yet, so the end-to-end acceptance item cannot be signed off from this repository.

### Work packages

1. **Control approach.** Validate whether embedded WebRTC/Jitsi control or a dedicated authorised PBX call-control service is appropriate. Existing read-only AMI account must NOT be given write privileges; provision a separate least-privilege control identity. *(Implemented as a dedicated control-service adapter; no embedded WebRTC stack runs in Laravel. `PbxCallControlGateway` calls an HTTPS `/v1/calls/{interaction_key}` + `/v1/commands` API with a bearer token, 2 s connect / 5 s timeout, redirects disabled and a strictly validated response schema. The control credential is separate from AMI: `CallControl::verifiedSnapshot()` aborts when the configured control identity equals the stored AMI username, `AmiClient` still accepts only `Login`/`Ping`/`Logoff`, and the browser receives masked destinations and command status only. `EXP_CALL_CONTROL_*` ships disabled in `.env.example`, and Jitsi remains the manual fallback.)*
2. **State machine.** Enforce Incoming → Agent Connected → Caller Held → Dentist Dialling/Ringing → Dentist Connected/Private Briefing → Caller–Dentist Bridge → Completed; allow cancel/resume and retry. *(Implemented: `CallControl::TRANSITIONS` is the single source of truth — `incoming → agent_connected → caller_held → dentist_dialling`/`dentist_ringing`/`dentist_connected → private_briefing → caller_dentist_bridge`, with `resume` back to `agent_connected`, `cancel_attempt` back to `caller_held` for a retry, `release_agent` keeping the bridge but withdrawing every action once the agent is gone, and `completed` terminal. Status responses offer only the actions the verified state allows, and every command must carry the snapshot `expected_version`; `CallControl::postcondition()` confirms it only when the gateway echoes the request key, the version changed and the reported state matches the declared transition.)*
3. **Safe actions.** Implement answer, hold/resume, dial selected listed dentist, cancel attempt, private handover, complete attended transfer and agent release. Protect with ownership, idempotency and PBX state verification. *(Implemented: routes require `view cases` + `control calls`, the request adds `edit cases`, and any account other than the assigned agent gets 404. Idempotency is layered — a per-intent UUID `request_key` (unique index plus payload-hash comparison), one unresolved command per interaction (`active_interaction_id` unique), identical retries returning the stored command while a new key or a changed payload is refused with 409, `throttle:30,1`, and a UI that blocks buttons while a command is pending — and a delivery timeout is recorded as `uncertain` rather than retried. `dial_dentist` accepts only a server-selected approved number of an active dentist that is manually marked available, and refuses an open safety draft, missing consent, an incomplete screen or a P1 case. `complete_transfer` requires the explicit human-acceptance attestation, an approved answered mobile leg inside the attempt window and an open `private_handover` bridge containing the agent and dentist; `release_agent` requires an open `consultation` bridge containing the caller and dentist. Each reservation writes `Call command reserved` to the `call-control` activity log.)*
4. **Fallback.** If dentist busy/no answer/voicemail, cancel attempt, return to caller and try another. If control plane unavailable, use manual Jitsi transfer and keep recording evidence. *(Implemented: the snapshot's `attempt_outcome` (`busy`, `no_answer`, `voicemail`, `failed`, `cancelled`) is shown to the agent, `cancel_attempt` puts the caller back on hold, and the agent can dial another approved dentist while every attempt keeps its own record. When the gateway is disabled, unapproved, unreachable, stale or inconsistent, the API reports `available: false` or 409/503 and stores no command, while the UI and messages point the agent back to the manual console. Only a dispatch timeout after a command has been reserved leaves a command record, marked `uncertain` so it cannot be repeated blindly. Manual transfers stay auditable through `Transfers/Index` and `Transfers/Show`, where `TransferTracker::record()` stores operator outcome and fallback intent with an attestation, a revision guard, time-window validation and an activity-log entry, without overwriting the PBX-observed attempt outcome.)*
5. **Verification.** Lab-test Local-channel topologies, channel masquerades, race conditions, hangup/recovery and caller continuity; enable in production only after successful technical validation. *(Partially met: 42 tests prove the contracts against a faked gateway — stale, mismatched or incomplete identity/media/topology evidence, race conditions, duplicate clicks, delivery timeout, rejection, offline telemetry, bridge preconditions and the human-acceptance gate — and production enablement additionally requires `EXP_CALL_CONTROL_LAB_APPROVED` plus per-request `topology_verified` and `agent_media_ready` flags. The real Local-channel, masquerade, hangup/recovery and caller-continuity lab run on the PBX is still outstanding, and no warm transfer has been driven through a live control service.)*

### Acceptance gate

- [ ] Agent can complete browser-controlled warm transfer end-to-end *(**open** — needs the lab control service and a real answered, briefed and bridged dentist call. The application side is ready: versioned snapshots, the full transition map, human-acceptance attestation, bridge preconditions and confirmation against verified PBX state.)*
- [x] Caller and dentist remain connected after agent leaves *(asserted from both sides: `CallControlTest` refuses `release_agent` without an open caller–dentist `consultation` bridge and confirms it only when the gateway reports `agent_present: false`; `TelemetryCoverageTest` "agent exit preserves an ongoing consultation until the caller and dentist hang up" keeps the interaction active from bridge membership after the agent leg ends. Live confirmation belongs to the open item above.)*
- [x] Repeated clicks cannot originate duplicate dentist calls *(asserted in `CallControlTest` — "call command retries never submit twice and new keys cannot bypass an unresolved command" and "a delivery timeout stays uncertain and cannot originate a duplicate command" — backed by the unique `request_key` and `active_interaction_id` indexes, the pending-command guard in `CallControl::status()` and the disabled-button state in `CallControls.vue`.)*

**Not yet met:** the first item only. Turning on `EXP_CALL_CONTROL_ENABLED` and `EXP_CALL_CONTROL_LAB_APPROVED` without a recorded lab session is a configuration change, not acceptance, so Phase 5 stays "code complete, lab gate open" until the real warm transfer and the failed-dentist recovery (busy, no answer, voicemail) have been executed and recorded.

### Implementation notes (2026-11-19)

- **Disabled by default.** `PbxCallControlGateway::configured()` requires `exp_call_control.enabled`, `exp_call_control.lab_approved`, an `https` URL with no embedded credentials, a token and an identity; otherwise `CallControl::status()` returns `available: false` with the Jitsi guidance and `store()` returns 503 without touching the network. That is what keeps a stock deployment on manual Jitsi.
- **Snapshot contract.** The gateway snapshot is validated against an explicit schema (interaction key, version, state, observed time, control identity, agent extension, caller/agent/dentist Uniqueids, presence and media flags, topology flag, last command key, attempt outcome). `CallControl::verifiedSnapshot()` then requires live telemetry, no coverage gaps, no blocking correlation exceptions, the configured screening extension, `topology_verified` and `agent_media_ready`, the snapshot Uniqueids present as caller/agent legs, an open dentist leg whenever a dentist state is reported, and an `observed_at` no older than 5 s (with 2 s future tolerance).
- **Command lifecycle.** Commands are reserved inside a transaction under `lockForUpdate()` on the case, written as `pending` with a payload hash, then dispatched with an `Idempotency-Key` header. Because dispatch happens after the reservation commits, repeated clicks and retries return the stored command instead of calling the gateway again. `CallControl::status()` polls `/v1/commands/{key}`, marks `rejected` results (freeing the reservation), and confirms only on the postcondition above; anything else stays pending, so the agent cannot issue a second action mid-flight. `uncertain` means the delivery result was never verified and forces the operator back to Jitsi before another attempt.
- **Human acceptance.** A network answer never becomes acceptance: `complete_transfer` needs the explicit attestation plus an approved answered mobile leg, and confirmation writes one fingerprint-guarded `operator_outcome: accepted` transfer event before resyncing `TransferTracker`, which is what finally marks the dentist connected in call history.
- **Emergency and consent floors.** P1 cases cannot dial or complete a transfer, dialling requires granted consent and a complete safety screen, dentist availability stays manual (never inferred from call activity), and the workspace disables the dial and transfer controls for P1 while leaving the Jitsi escape hatch visible.
- **Operator surfaces.** `Workspace/Show.vue` embeds `CallControls.vue` for the assigned agent with `control calls` and `edit cases`, polling verified status every 3 s while the tab is visible and showing the current state, the last attempt outcome, any pending command and the ten most recent commands. `Transfers/Index` adds live, attempts, consultations, failures and analytics views; `Transfers/Show` shows the attempt timeline, related retries and the record-evidence form.
- **Deliberate limits.** Laravel does not carry media: answering and bridging happen in the control service, and the application accepts a snapshot only when that service reports media and topology as verified. `completed` is gateway-reported only. `control calls` is seeded to Agent, Supervisor and Administrator; the Supervisor breadth is the Phase 6 item already noted under Phase 1, and the ownership check means a Supervisor can still only drive their own assigned case.
- **Tests.** `CallControlTest` (42) covers permissions and ownership, disabled/unapproved fallback without network traffic, the action map for every state, idempotency and unresolved-command guards, stale or mismatched snapshot fields, delivery uncertainty, confirmation through changed state, approved-number and safety-screen dialling rules, human-acceptance and bridge preconditions, gateway rejection, offline telemetry and verified-identifier targeting. `TransferTrackingTest` (19) covers the separated transfer permissions, timings, dial outcomes, retries, operator outcome and fallback evidence, stale revisions, redaction, analytics and the emergency retry rule.
- **Switches.** `EXP_CALL_CONTROL_ENABLED`, `EXP_CALL_CONTROL_LAB_APPROVED`, `EXP_CALL_CONTROL_URL`, `EXP_CALL_CONTROL_TOKEN` and `EXP_CALL_CONTROL_IDENTITY` are documented in `.env.example`; the snapshot age ceiling is the `services.exp_call_control.snapshot_max_age_seconds` config value (5 s).

### Dependencies and hand-off

Phase 4's verified event topology plus, from the PBX owner: a separately provisioned control service (base URL, bearer token and control identity — never the read-only AMI account), lab approval for the Local-channel and masquerade behaviour of the screening extension, and a warm-transfer matrix covering an answered dentist, a busy, a no-answer and a voicemail destination. Hand-off is the tested control workflow with its state machine, idempotency guarantees and manual Jitsi fallback intact; live acceptance of the warm transfer stays with Phase 5's open gate.

## Phase 6 — Operational reports, hardening and Ubuntu pilot

**Deliverable:** Basic management visibility, secure deployment and signed-off production readiness.

> **Status (2026-11-19):** the reporting work package is **code complete** — catalogue, KPI engine, run snapshots and audited export ship behind explicit permissions and 8 new tests. The remaining Phase 6 work is operational: Ubuntu host provisioning, TLS, backup/restore drill, privacy/retention sign-off, the Viewer decision and the signed-off live pilot. Those are deployment and governance gates, not unfinished application code.

### Work packages

1. **Reports.** Today totals, agent answered, transferred, no-answer/failed, dentist answer rates, ringing and verified bridge durations; export authorised filtered history only. *(Implemented as `Reporting::definitions()` — ten published definitions over a 43-KPI catalogue (`Reporting::KPI_CATALOGUE`), including `average_ringing_seconds` from dentist mobile legs and `average_verified_bridge_seconds` from closed caller–dentist `consultation` bridges — computed by `ReportMetrics` from interactions, agent legs, transfer attempts, call bridges, cases, callbacks, QA reviews, incidents, corrective actions, rosters, CDR records and correlation exceptions. `Reports/Index` lists only the definitions the signed-in user may read and shows the latest saved run; `Reports/Show` previews today (or an explicit period) grouped by category, prints every KPI's value with its numerator/denominator, definition and exclusions, and renders "Not available" instead of a guessed zero. "Save run" writes a `report_runs` row plus one `kpi_snapshots` row per KPI with a SHA-256 checksum, governance payload (time zone, definition revision, requested vs computed keys) and a revision that increments on re-run without overwriting the earlier figure. "Export KPI evidence" streams a CSV of the same period and needs the separate `export reports` grant; every save and export writes an `activity('reporting')` entry. Filtered call history export stays where it was, behind `export call history`.)*
2. **Security.** TLS, MFA for privileged users, encrypted answer/notes, access audit, backup/restore, privacy notice, retention and incident response approvals. *(Partly met in code: Fortify two-factor authentication and passkeys are enabled in `config/fortify.php`; screening answers, case notes, callback and incident narrative fields use `encrypted` casts; administration changes pass through `AuditAdministration` and every sensitive read/export writes an activity-log entry enumerable in `Administration → Audit`; AMI event retention runs hourly through `exp:prune-telemetry`. TLS, MFA enforcement for privileged roles, backup/restore automation, the privacy notice publication and the recorded retention/incident-response approvals are deployment tasks and stay open — see the notes below.)*
3. **Deployment.** Deploy existing Laravel app to Ubuntu; configure MySQL, Redis/Horizon, scheduler, persistent AMI listener systemd unit, health checks and TLS; keep local Mac for development. *(Not delivered in this repository. What exists to deploy is the `exp:ami-listener` command with reconnect generations and heartbeat, the scheduler entries in `routes/console.php`, Horizon, and Laravel's `/up` health endpoint backed by `LiveOperationsSnapshot::connectionFreshness()`; the systemd unit, host hardening, TLS terminator and backup target belong to the Ubuntu hand-off.)*
4. **Pilot.** Controlled live call matrix, supervisor review of records, user training, rollback, monitoring and formal sign-off; validate PBX credentials, event timestamps and clocks. *(Open. Phase 4's controlled-call matrix and Phase 5's warm-transfer lab session are its prerequisites, and none of the three can be signed off from the repository.)*
5. **Handover.** Admin guide, agent SOP, architecture, deployment runbook, backup recovery and known limitations. *(Not written as separate documents in this repository; this roadmap, the Phase 1–5 implementation notes and the appendix operational data are the current technical hand-over source. Writing the SOP and runbook requires the Ubuntu decisions above.)*

### Acceptance gate

- [x] Supervisor can search and inspect calls without exposing unauthorised data *(asserted across the history and reporting surfaces: `Interaction::scopeVisibleInHistory()` and `CallerCase::visibleTo()` scope reads to the agent's own records unless `view all cases`; caller numbers are masked unless the association allows them; `CallJourneyController` writes a "Call detail viewed" activity entry and `export()` needs `export call history`; `ReportDefinition::scopeAccessibleBy()` hides the sensitive executive summary without `view executive reports`, `ReportController::authorised()` re-checks the definition's own permission server-side, and the executive report publishes aggregates only — no caller, agent or dentist identity. `ReportingTest` covers the agent denial, the Supervisor/executive split, the CSV grant and the audited export.)*
- [x] Production listener reconnects and reports freshness *(implemented and tested at code level: `PbxConnection` keeps `reconnect_generation`, `last_connected_at`, `last_event_at` and `last_heartbeat_at`; `exp:ami-listener` reconnects under a lock and updates the heartbeat; `LiveOperationsSnapshot::connectionFreshness()` derives `live`/`degraded`/`stale`/`offline`/`unconfigured` and every screen that consumes it says the screen is not proof that no call is waiting; `/up` is the deployment health endpoint. `AmiListenerTest` (13) and `TelemetryCoverageTest` (7) assert reconnect, heartbeat staleness and gap handling. Running it for real on the Ubuntu host belongs to the item below.)*
- [ ] Signed-off real-call pilot and restore drill completed *(**open** — needs the Ubuntu deployment, a recorded backup and restore drill, the Phase 4 controlled-call matrix, the Phase 5 warm transfer and a written service-owner sign-off.)*

**Not yet met:** the third item, plus the parts of item 2 that are security configuration rather than code — TLS, enforced MFA for privileged roles, backup/restore automation, privacy-notice publication and recorded retention decisions. Nothing in the repository can substitute for those.

### Implementation notes (2026-11-19)

- **Catalogue, not ad-hoc screens.** Ten definitions are seeded from `Reporting::definitions()` by `ReportCatalogueSeeder`, so every install publishes identical wording: daily and weekly operations, warm transfer quality, callback follow-up, quality assurance, dentist engagement, safety and incidents, short-call dispositions and the executive summary. Each definition lists KPI keys; `Reporting::compute()` resolves them against `Reporting::KPI_CATALOGUE` (43 KPIs) and `ReportMetrics::values()`. Two catalogue defects were fixed while wiring this: twelve KPI entries were accidentally nested inside `ultra_short_contacts`, and no `view reports` permission existed, so every definition would have been unreachable.
- **Figures are reproducible.** Percentages publish numerator over denominator, averages publish the sum and the count of attempts that carried the metric, and a KPI with no evidence returns `null` — rendered as "Not available" — rather than `0`. A zero denominator therefore never reads as zero percent, and a network answer never counts as human acceptance (`transfer_answer_rate` and `transfer_acceptance_rate` stay separate KPIs). Estimated consultation cost stays empty until `REPORTING_CONSULTATION_TARIFF_PER_MINUTE` is set: the tariff is a commercial decision, not a default.
- **Run snapshot contract.** Saving a run locks the existing row for the same definition, period and source, increments `revision`, replaces the KPI snapshots and stores `totals`, a `governance` payload and a SHA-256 `checksum` over the computed rows. A re-run of the same day keeps one run row with visible revision history; a new period creates a new row. Because each `kpi_snapshots` row stores the definition, exclusions and computed time, an exported or printed figure can always be traced back to the values that produced it.
- **Export is separate and audited.** `Reports/Show` renders the export link only when `export reports` is granted, and `ReportController::export()` re-checks it. The CSV carries the period, KPI key, label, category, unit, value, numerator, denominator, definition and exclusions — not caller data — and the action is written to the audit trail. Filtered call-history CSV stays a different permission (`export call history`) because it contains caller-level records.
- **Deliberate limits.** Manual runs only: no scheduled generation job, no email or PDF delivery and no report subscription exists yet (`report_runs.source` already carries the `scheduled` value for that future work). `breakdown_count` stays `0` because there is no per-agent or per-dentist breakdown table. CSV is streamed and audited rather than stored as an `exports` artefact, so there are no orphaned files to expire. The window is capped at 92 days (`REPORTING_MAX_WINDOW_DAYS`) so one request cannot scan the whole archive; a longer period belongs to a scheduled run with its own approval.
- **Role posture.** `view reports` is seeded to Supervisor, QA Reviewer, Lead Dentist / Clinical Supervisor, Platform Manager and Administrator; `view executive reports` and `export reports` go to Executive / Management and Administrator only. Agent stays on "own if enabled" and receives no reporting permission. The missing `Viewer` role and the Supervisor breadth remain the open role decisions recorded in the appendix.
- **Tests.** `ReportingTest` (8) covers the missing-permission denial, the Supervisor/executive split (`view reports` sees nine definitions and is refused the executive summary; the explicit grant unlocks all ten), KPI values against constructed telemetry and transfer evidence (including the ringing and verified-bridge averages), the null-instead-of-zero rule, snapshot persistence with revision increment and unchanged snapshot count, the CSV grant plus audit entry, and the inverted/oversized period refusal. `PrimaryNavigationTest` continues to assert the permission-filtered shell, which now includes a Reports menu.
- **Switches.** `REPORTING_CONSULTATION_TARIFF_PER_MINUTE` and `REPORTING_MAX_WINDOW_DAYS` are documented in `.env.example` and `config/reporting.php`. No feature flag hides the reports module: it is gated purely by permissions, so a pilot account can be granted or denied it individually.

### Dependencies and hand-off

Phases 1–5 plus the operational decisions that no repository can make: an Ubuntu host with MySQL, Redis/Horizon, the scheduler, TLS and the AMI listener under systemd; a tested backup and restore procedure; a published privacy notice and an approved retention period for caller and health data; enforced MFA for privileged accounts; the `Viewer` role decision (read-only permission plus assigned-scope filtering) and the Supervisor scope decision (`edit cases`, `review cases` and `control calls` versus cross-agent monitoring only). Hand-off is the reporting module with its catalogue, permissions, audited export and reproducible snapshots, plus the evidence already recorded in the Phase 1–5 notes; the production pilot itself stays open until the controlled-call matrix, the warm transfer and the restore drill have been executed and signed off.

## Implementation checkpoints

- **Early usable release (after Phase 3):** Agents use existing Jitsi call buttons while EXP captures answers and provides the full caller–dentist history/detail view. This is the recommended first operational milestone. *(Phase 3's history, detail, attempts, timeline, duration, privacy and export views are implemented; live-handset confirmation of attribution remains part of the Phase 4 lab gate.)*
- **Telemetry-validated release (after Phase 4):** Call timing and final dentist attribution have passed real PBX acceptance tests. *(Phase 4 code and its 79 tests are in place; the live controlled-call matrix, not the application work, is what still separates this milestone from sign-off.)*
- **Browser-controlled release (after Phase 5):** Agent uses EXP buttons for answer, hold, private handover and complete transfer, after lab validation. *(The controls, state machine and 61 tests are in place and ship disabled; the lab warm transfer — not the application work — is what still separates this milestone from sign-off.)*
- **Production pilot (after Phase 6):** Secure Ubuntu deployment, monitoring, recovery and readiness checks are complete. *(The reporting screen, its permission split and the audited export are in place and tested; the Ubuntu deployment, restore drill and service-owner sign-off — not the application work — are what still separate this milestone from completion.)*

## Suggested database mapping

Retain existing `users`, `dentists`, `dentist_numbers`, `interactions`, `call_legs`, `call_events`, `call_bridges`, `call_timing_metrics`, `cases`, `screening_protocols`, `screening_questions`, `screenings`, `screening_answers`, `consent_records`, `case_notes`, `transfer_attempts`, `consultation_sessions` and `transfer_events` where already implemented. Add only necessary projections/indexes for the simplified `calls` history view. Do not create duplicate canonical call stores or delete legacy tables as a simplification shortcut.

## Critical decisions to confirm before development

- Already-configured screening questions, privacy/consent text and emergency escalation instructions.
- Whether initial answer/transfer controls remain in Jitsi or browser control is required immediately.
- Exact inbound DID/trunk routing and the agent extension used in production (existing blueprint references DID `0596921095` and extension `204`; validate actual routing).
- Dentist directory ownership, configured phone numbers, availability representation and human acceptance confirmation.
- Permitted retention, export policy, and who may see full caller responses.

## Source and scope

Based on *EXP | Agent Call & Dentist Transfer Console — Complete simplified product requirements and implementation blueprint*, version 1.0, 19 November 2026, and its referenced EXP Operations Console design. This roadmap reorganises the specified features into buildable phases; sequencing, dependencies and acceptance gates are implementation recommendations.


---

# Operational seed data and configured question wording (19 November 2026)

## Initial dentist directory

| Dentist | Phone number |
|---|---|
| Dr. Paapa Puplampu | 0556760659 |
| Dr. Gwendolyn Amarquaye | 0593989921 |
| Dr. Kakraba Quarshie | 0556760650 |
| Dr. Richard Atuwo | 0556760651 |
| Dr. Philip Frimpong | 0257956504 |
| Dr. Marrie | 0531015455 |
| Dr. Asiamah | 0531015150 |

Store numbers as strings, retain leading zeroes, confirm each dentist is available before live transfer.

## Exact agent screening questionnaire

| # | Purpose | Question | Suggested response input |
|---|---|---|---|
| 1 | Open reason | Please tell me, in your own words, what made you contact us today. | Long text |
| 2 | Location | Where in your mouth are you experiencing the problem? | Text |
| 3 | Onset | When did you first notice it? | Text / date description |
| 4 | Pattern | Is it constant, or does it come and go? | Constant / comes and goes / unsure |
| 5 | Severity | How would you describe it—mild, moderate or severe? | Mild / moderate / severe / unsure |
| 6 | Function | Is it affecting eating, drinking, sleeping or speaking? | Multi-select + notes |
| 7 | Swelling / infection warning | Have you noticed swelling in your face, jaw, mouth or neck? | Yes / no / unsure + notes |
| 8 | Breathing / swallowing | Are you having any difficulty breathing or swallowing? | Yes / no / unsure + notes |
| 9 | Bleeding / injury | Is there bleeding that will not stop, or has a tooth or part of your mouth been injured? | Yes / no / unsure + notes |
| 10 | Relevant context | Have you already spoken to a dentist or received treatment for this concern? | Yes / no / unsure + details |

The question wording above must remain unchanged unless a new questionnaire version is saved by the Administrator. Capture answer, unanswered/declined state, timestamp, agent and version. Flag breathing/swallowing difficulty, uncontrolled bleeding and concerning swelling for approved escalation; a routine transfer must not hide these warnings.

## Exact delivery placement

- **Phase 1:** Seed all seven dentists; version and seed all ten questions; provide administrator maintenance screens. Acceptance: exact seed data and correct leading zeroes.
- **Phase 2:** Incoming caller card; all ten questions with autosave and warning flags; select a listed dentist. Acceptance: every response persists against the call ID. *(Implemented — each answer is stored against the case/interaction with its prompt snapshot, status, questionnaire version, author and timestamp.)*
- **Phase 3:** Call History displays caller, agent, transferred dentist name/phone, transfer status, answer count and call times. Clicking opens the full question-and-answer record, notes, all dentist attempts, warning flags and audit history. Acceptance: two dentist attempts remain visible and the connected destination is correct.
- **Phase 4:** Correlate PBX legs and bridge events to show dentist ring/dial-to-answer, private handover, caller-dentist connected time and hangup. Do not treat trunk answer as verified human answer; unknown values remain null. *(Implemented in `CallTimelineProjector`, with CDR/CEL reconciliation and gap invalidation; the controlled live-call confirmation remains the Phase 4 gate.)*
- **Phase 5:** Implement secured browser Answer, Hold, Dial Dentist, Private Consult, Complete Transfer, Cancel and Return to Caller. Existing read-only AMI monitoring does not provide these controls. Acceptance: real warm-transfer test and no-answer recovery. *(Implemented through `CallControl` + `PbxCallControlGateway` + `CallControls.vue`, with separate control credentials and a disabled-by-default switch; the real warm-transfer and no-answer recovery remain the Phase 5 gate.)*
- **Phase 6:** Role access, privacy and clinical review, Ubuntu deployment, monitoring, reports and pilot sign-off. *(Implemented for reporting: ten definitions, 41 KPIs, saved snapshots, audited CSV export and the `view reports` / `view executive reports` / `export reports` split. Privacy and clinical sign-off, Ubuntu deployment, monitoring and the pilot itself remain organisational gates.)*

## Call detail - required fields

1. Caller number/name, inbound number, call ID, date/time, agent and outcome.
2. All ten question prompts and saved answers, version, notes, warning flags and escalation action.
3. Selected dentist and **actual connected dentist**, including names and dialed numbers.
4. Every dentist transfer attempt, dial/ring/answer/failed/cancelled timestamps and outcome.
5. Inbound wait, agent talk, hold, dentist ringing when observable, private consultation and caller-dentist connected duration.
6. PBX event evidence, correlation confidence, answer-edit audit and authorized access.

**Data model rule:** Save dentist ID plus a historical name/number snapshot per transfer attempt; save question ID/version/text snapshot plus answer per call. Never overwrite failed attempts when a later dentist answers.


## Confirmed operating rules — no approval workflow

- The ten screening questions supplied for EXP **have already been approved**. They are seeded as the active questionnaire from Phase 1. No questionnaire approval, submission, review, rejection or activation queue.
- Agents answer calls, save responses, select any active listed dentist and transfer without managerial approval.
- Administrators manage dentists and questionnaire wording directly. Changes are versioned and audited, but not submitted for approval.
- Technical verification, privacy controls and emergency escalation instructions are operational safeguards, not approval workflows.

## Four roles — access matrix

| Capability | Administrator | Supervisor | Agent | Viewer |
|---|:---:|:---:|:---:|:---:|
| Live dashboard | Yes | Yes | Own | Read only |
| Answer, hold, dial, transfer | Optional | No | Own calls | No |
| Enter / edit questionnaire answers | Yes (controlled) | No | Own active calls | No |
| Call History and Call Detail | All permitted | Team | Own | Permitted, read only |
| View caller, connected dentist, all answers, attempts | Yes | Yes | Own | Permitted, read only |
| Manage dentist directory | Yes | No | No | No |
| Edit questionnaire configuration | Yes | No | No | No |
| User/role and PBX settings | Yes | No | No | No |
| Reports | Yes | Yes | Own if enabled | Read only |
| Export sensitive records | Explicit grant | Explicit grant | No | No by default |

> **Status (2026-11-19):** this matrix and the Viewer requirements below are the target design, not the current state. `Agent`, `Supervisor` and `Administrator` are seeded today, and the reporting grants now exist (`view reports` for Supervisor, QA Reviewer, Lead Dentist / Clinical Supervisor, Platform Manager and Administrator; `view executive reports` and `export reports` for Executive / Management and Administrator). The `Viewer` column still has no role, no read-only case permission and no seeder entry, so read-only access to Call History, Call Detail or Reports cannot be granted today. See the implementation status table and Phase 1 implementation notes.

### Viewer security requirements

1. Enforce read-only permissions in both Vue/Inertia UI and Laravel policies/API routes; hiding buttons is insufficient.
2. Viewer sees only assigned/permitted call records, including caller identity, connected dentist, the ten saved answers, transfer history and durations.
3. Mask sensitive fields if the Viewer's assigned scope does not permit them; audit each detailed record access.
4. Reject all create, update, delete, answer, hold, dial, transfer and settings operations for Viewer.

### Revised phase acceptance requirements

- **Phase 1:** Seed the seven dentists and the ten already-approved questions; create Administrator, Supervisor, Agent and Viewer roles. No approval status or approval workflow.
- **Phase 2:** Agent uses all ten questions immediately; responses autosave without approval.
- **Phase 3:** Viewer can open permitted call detail and see caller, connected dentist, exact answers and all transfer attempts, but cannot modify anything.
- **Phase 4:** Viewer sees permitted live and historical timings as read-only data.
- **Phase 5:** Agent transfers to active listed dentists without supervisor approval; Viewer cannot invoke call controls. *(Agent-owned cases can be driven from EXP through `control calls` with no approval step, and every route requires physical ownership; the Viewer half of this clause still rests on the missing `Viewer` role.)*
- **Phase 6:** Test permission denial for Viewer writes, direct questionnaire activation, and absence of approval queues.

> **Status (2026-11-19):** every clause that depends on `Viewer` is still a requirement rather than a verified behaviour. Phase 6 delivered the reporting permissions (`view reports`, `view executive reports`, `export reports`) and their denial tests, but the two open role decisions must still be settled before the Phase 6 acceptance gate can pass: add the read-only `Viewer` role (dedicated read-only case permission plus assigned-scope filtering) and decide whether Supervisor keeps `edit cases`, `review cases` and `control calls` or is narrowed to cross-agent monitoring.
