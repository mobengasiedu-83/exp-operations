# EXP Operations — Viewer Role Guide

**Audience:** Read-only viewers (role: **Viewer**) who monitor the EXP dental line without changing a record
**Applies to:** EXP Operations web console (Laravel + Inertia/Vue)
**Service window:** Monday to Friday, 10:00–14:00 Ghana time (Africa/Accra), closed on Ghana public holidays
**Document version:** 1.0

---

## Contents

1. [About this manual](#1-about-this-manual)
2. [Your role and what you can open](#2-your-role-and-what-you-can-open)
3. [Getting set up](#3-getting-set-up)
4. [Finding your way around](#4-finding-your-way-around)
5. [The service window](#5-the-service-window)
6. [The Dashboard](#6-the-dashboard)
7. [Call history: choosing the right screen](#7-call-history-choosing-the-right-screen)
8. [Agent calls](#8-agent-calls)
9. [DID journeys](#9-did-journeys)
10. [All calls — the reconciled history](#10-all-calls--the-reconciled-history)
11. [Opening and reading a call record](#11-opening-and-reading-a-call-record)
12. [Reading the evidence inside a call record](#12-reading-the-evidence-inside-a-call-record)
13. [What is masked or restricted — and why](#13-what-is-masked-or-restricted--and-why)
14. [Reports: the catalogue](#14-reports-the-catalogue)
15. [Running, saving and exporting a report](#15-running-saving-and-exporting-a-report)
16. [Reading a report honestly](#16-reading-a-report-honestly)
17. [The dentist directory (read-only)](#17-the-dentist-directory-read-only)
18. [Dentist numbers and credentials](#18-dentist-numbers-and-credentials)
19. [Dentist rosters, availability and performance](#19-dentist-rosters-availability-and-performance)
20. [Privacy, audit and retention](#20-privacy-audit-and-retention)
21. [Troubleshooting](#21-troubleshooting)
22. [Daily and weekly checklist](#22-daily-and-weekly-checklist)
23. [Glossary](#23-glossary)
24. [Quick reference card](#24-quick-reference-card)

---

## 1. About this manual

This is the complete operating manual for an EXP **Viewer**. It explains:

- what your account can open and what it deliberately cannot;
- how to read the Dashboard without mistaking it for a work queue;
- how to find a caller's journey in the three call-history screens;
- how to read a call record, its timings and its evidence flags;
- which fields are masked, why, and what to do when you need the full picture;
- how the dentist directory and the published reports are built;
- what is audited whenever you open a record.

Three principles run through the whole console:

1. **The console reports evidence, it does not guess.** If the PBX never reported a key press, the screen says *option not recorded*. If a bridge was not verified, the call is shown as *unverified* or *not connected*. Missing measurements stay blank ("Not available") instead of being estimated.
2. **You are a reader.** You cannot answer, hold, dial, transfer, screen, annotate or configure anything. Every write button is absent from your screens and every write endpoint refuses a Viewer on the server as well.
3. **Sensitive content is scoped.** Caller names and clinical case content are only shown to a Viewer whose role has also been granted `view cases`. Masking is a permission decision, not a display bug.

If this guide ever disagrees with what your manager tells you during a live incident, follow your manager first, then ask them to have this guide corrected.

**Related manuals.** This is one of three role manuals. The *Agent role guide*
covers answering, screening, SBAR handover and transfers; the *Super
Administrator role guide* covers administration and integration health. Each is
visible only to the roles that hold its permission, so you will normally see
only this manual in your **Guides** menu.

## 2. Your role and what you can open

The **Viewer** role is deliberately narrow and entirely read-only. It normally carries these permissions:

| Permission | What it lets you do |
|---|---|
| `view call history` | Open **Calls → Agent calls**, **DID journeys**, **All calls**, and any call record in your scope |
| `view all cases` | Read call records across the whole service, not only cases assigned to you. It grants no edit rights |
| `view reports` | Open the published operational reports and compute their KPIs |
| `view dentists` | Read the dentist directory, numbers, credentials, rosters, availability and performance |
| `view viewer guides` | Open this guide |

What that means in practice:

- Your menu shows **Dashboard**, **Calls** (with three sub-pages), **Reports**, **Dentists** and **Guides**.
- The sidebar footer, and the header icon on wide screens, hold the **Viewer guide** link. The **Agent guide** only appears for accounts that hold `view agent guides`; a Viewer normally does not.
- You **never** see **Agent Workspace** or **Administration**. Typing `/workspace` or `/administration` directly returns **403 Forbidden**.
- You cannot switch the live console on. Pages that need `view pbx` are closed to the Viewer role, and that permission is also one of the signals the console uses to decide whether to show the Administration hub.
- You do **not** receive the **Executive summary** report. It is marked sensitive and needs `view executive reports`.
- **Exports are off by default.** Call-history CSV needs `export call history`; report CSV needs `export reports`. If your manager wants you to export, they must grant that permission explicitly.

> **If a menu or button is missing, assume it is a permission setting, not a fault.** Ask your manager or administrator; do not attempt a workaround.

### 2.1 What "read-only" covers

The console refuses every Viewer write on the server, not just in the browser. In particular:

| Action | Result for a Viewer |
|---|---|
| Create or update a case, consent, answer, note or disposition | 403 Forbidden |
| Answer, hold, resume, dial, cancel or complete a transfer | 403 Forbidden |
| Approve, activate or retire a questionnaire | 403 Forbidden |
| Add or edit a dentist, number, credential, roster or override | 403 Forbidden |
| Change settings or acknowledge alerts | 403 Forbidden |
| Terminate another user's session | 403 Forbidden |

The one exception is a **report snapshot**: pressing **Save run** on a report writes an immutable, checksummed snapshot of the figures you were already allowed to see. It changes no call, case, answer or configuration record, and it is audited against your account. If your organisation wants Viewers to read without saving, leave the button unused and rely on the figures on screen.

---

## 3. Getting set up

### 3.1 Before your first session

1. **Account** — your manager creates your user with the **Viewer** role and a verified email address.
2. **Email verification** — the console requires a verified email before any signed-in screen opens. Click the link in the verification email on first sign-in.
3. **Two-factor authentication (2FA)** — enabled per account from **Settings → Security**. You will be asked for a 6-digit authenticator code, or a passkey prompt, after your password.
4. **Browser** — use a current Chrome, Edge, Firefox or Safari. Keep one console tab per topic; opening the same call record in several tabs is harmless but confusing when you compare timestamps.
5. **Time zone** — the service window is always labelled in Ghana time. Call-record timestamps are UTC and the call page says so in its header. Read the label; do not convert by hand.

### 3.2 Signing in

1. Open the console URL given by your administrator.
2. Enter your **email** and **password**, then **Log in**.
3. If 2FA is enabled, enter the 6-digit code or approve the passkey prompt.
4. You land on the **Dashboard**. Your name and menu appear in the sidebar.

**Forgot your password?** Use **Forgot your password?** on the sign-in screen. A reset link is emailed to you. If it does not arrive within a few minutes, check spam, then ask your administrator to confirm the address on your account.

### 3.3 Your settings

Open **Settings** from the account menu in the sidebar footer:

- **Profile** — name and email address.
- **Security** — change password, enable or disable 2FA, manage passkeys. Changing security settings asks you to re-enter your password first.
- **Appearance** — light, dark or system theme. Dark mode is fully supported.

### 3.4 Signing out

Sign out at the end of every session, especially on a shared workstation: sidebar footer → **Log out**. Administrators can review and terminate active sessions; a terminated session is signed out on its next request.

## 4. Finding your way around

| Menu item | Where it goes | What you use it for |
|---|---|---|
| **Dashboard** | `/dashboard` | Landing page and read-only summary |
| **Calls → Agent calls** | `/calls/agent` | Calls that reached the agent option or an agent leg |
| **Calls → DID journeys** | `/calls/did` | Every call to the assigned line, including self-service |
| **Calls → All calls** | `/live-operations/calls` | Reconciled history with filters and drill-down |
| **Reports** | `/reports` | Published operational reports and their KPIs |
| **Dentists** | `/dentists` | Read-only directory, numbers, credentials, rosters, availability, performance |
| **Guides → Viewer guide** | `/guides/viewer` | This manual, with an "On this page" index |

The **Guides** menu is permission-filtered exactly like every other menu: you only see the manuals your role can open. The same rule is enforced in the controller, so a guide you cannot see is also a guide you cannot open by typing its URL.

On the call-history screens you will also see two badges in the page header:

- **Service window badge** — `Open · Mon–Fri 10:00–14:00` or `Closed · …` with the next opening time.
- **PBX badge** — `PBX live`, `PBX stale` or `PBX offline`, with the heartbeat age.

A **stale** or **offline** badge does not block you from reading stored history. It means live telemetry has stopped arriving, so the newest records may be incomplete. Treat the newest rows with more caution, and tell your manager.

### 4.1 The two "views" of the console

It helps to separate what you see into two layers:

| Layer | What it is | Who writes it |
|---|---|---|
| **Telephony evidence** | Call arrivals, legs, menu presses, ring/answer times, bridges, hang-ups | The PBX listener and its reconciliation jobs |
| **Case documentation** | Consent, caller details, screening answers, notes, dispositions | The agent working the call |

As a Viewer you can always read the first layer. The second layer is only visible when your role also holds `view cases`; otherwise the record shows *Restricted / not given* and *No case record available for your permissions*.

---

## 5. The service window

The published window is **Monday to Friday, 10:00–14:00 Ghana time (Africa/Accra)**, closed on Ghana public holidays.

The window matters to a Viewer for three reasons:

1. **Call volume outside the window is not a fault.** A call at 15:00 is expected to reach a closed message, not an agent.
2. **Report windows are Ghana calendar dates.** A daily report for 19 November covers 00:00–23:59 Ghana time, even though the console displays call timestamps in UTC.
3. **The service badge** on the call screens is the authoritative statement of whether the line should have been staffed at the time you are reviewing.

Administrators maintain the window and its holiday exceptions. If the badge shows a window you know is wrong, report it; do not assume the calls outside it were mishandled.

---

## 6. The Dashboard

The Dashboard is a landing page, not a work queue. For a Viewer it shows:

- the **Pepsodent · Talk to a Dentist** identity and the "Operations overview" heading;
- the statement that network answer does not confirm a human dentist accepted the call;
- a read-only notice: *"You have read-only access. Live call activity is available to operational roles only."*

That notice is expected. Live call activity is gated by `view pbx`, which the Viewer role does not hold. If your account has been granted `view pbx` for a temporary monitoring task, the Dashboard additionally shows:

- **KPI tiles** — inbound journeys, answered by agent, dentist ringing, active now;
- **VitalPBX AMI health** — last heartbeat, last PBX event, agent presence (inferred) and a freshness badge;
- **Open live console** button, which opens the read-only live operations view.

If you see a PBX error on that card, report it to your manager before drawing conclusions from any live number. Stored call history is unaffected and remains the record of truth.

> **Dashboards are for orientation.** When a figure matters, open the call list or the report that produced it. The Dashboard refreshes on its own; call history and report snapshots do not move.

## 7. Call history: choosing the right screen

The console keeps three call-history screens because they answer different questions. They read the same underlying records; only the starting point and the filters differ.

| Question you are asking | Screen |
|---|---|
| "Did this caller reach an agent?" | Agent calls |
| "What did the caller press on the menu, and did anyone answer?" | DID journeys |
| "Which calls ended without a verified dentist?" | All calls |
| "What exactly happened on this one call?" | Open the call record (§11) |
| "How many calls did we answer last week?" | Reports (§14) |

All three screens share the same visual language: a hero header with today's counters, the service and PBX badges, a filter bar, and a list you can drill into.

> **Phone numbers are always masked.** The console stores a fingerprint for matching and a masked display value such as `+233241*****67`. It never stores the full number in readable form. Searching accepts a full number you already hold from the caller; the console matches it by fingerprint without exposing it back to you.

---

## 8. Agent calls

**Route:** `/calls/agent`

This screen lists only calls where the caller pressed the agent option on the IVR tree, or where the PBX delivered the caller to an agent leg. Self-service calls are excluded here; use DID journeys for those.

### 8.1 The counters

| Tile | Meaning |
|---|---|
| **Today's total** | Calls received on the current service day |
| **Reached the agent** | Calls that pressed the agent option or reached an agent leg |
| **Screened** | Calls with a case record and recorded answers |
| **Connected** | Calls with a **verified** dentist bridge |

The counters describe the service day that the console considers current, not the date range in the filter form.

### 8.2 Filters

| Filter | What it does |
|---|---|
| **From / To** | Service-date range, inclusive |
| **Evidence** | *Menu option 3 or agent leg* (default) or *Also include case-only history* |
| **Caller number** | Full or partial number, e.g. `233*****34` |

**Apply filters** reloads the list; **Reset** clears the form back to the default basis.

### 8.3 Each row

Rows show the caller, the stage or outcome, the duration, the connection state (`verified`, `unverified`, `none`), warning chips and a link into the call record. Expanding a row reveals the journey chips and — only when you also hold `view cases` — a link to the case.

### 8.4 When to use it

Use Agent calls to answer: *"How many callers did we actually screen today, and how many reached a dentist?"* It is the closest thing to the operational view an agent sees, but with no working controls.

## 9. DID journeys

**Route:** `/calls/did`

This screen lists **every call that arrived on the assigned DID**, including callers who chose a recorded message or the callback option and never spoke to anyone.

### 9.1 The counters

| Tile | Meaning |
|---|---|
| **Calls today** | Every arrival on the assigned DID |
| **Reached an agent** | Pressed the agent option or reached an agent leg |
| **Self-service** | No agent contact at all |
| **Live now** | Calls still in progress |

### 9.2 Filters

| Filter | What it does |
|---|---|
| **From / To** | Service-date range |
| **Menu option** | Any option recorded on the IVR tree, listed with its label |
| **Reached an agent** | *Either*, *Agent contact* or *Self-service only* |
| **Caller number** | Full or partial number |

The page header also states the assigned DID(s) and the service window, so you can prove which line a call arrived on.

### 9.3 Each row

Each row shows the caller, the menu the caller heard, the **option they pressed** — or *Menu option not recorded* — and how the journey ended.

This is the screen to open when someone claims they "kept pressing 3 and nobody answered". If the PBX captured the key press, you will see the option and the time. If it did not, the row says so; that is an evidence gap, not proof that nothing was pressed.

---

## 10. All calls — the reconciled history

**Route:** `/live-operations/calls`

This is the full, reconciled list. It is the best starting point when you are investigating a single call or auditing a period.

### 10.1 Filters

| Filter | Options |
|---|---|
| **Scope** | All calls, or My calls |
| **Connection** | All, Connected (verified), Unverified bridge evidence, No connected dentist |
| **Caller name** | Exact name match when your role holds `view cases`; otherwise returns nothing |
| **Call ID** | The numeric console call identifier |
| **Agent** | Any agent with recorded cases |
| **Dentist** | Any dentist in the directory |
| **From / To** | Date range |
| **State** | All, Active / missing hangup, Completed |
| **Phone number** *(Advanced)* | Full dialable number, matched by fingerprint |
| **Correlation ID** *(Advanced)* | The PBX correlation key |

### 10.2 Columns

| Column | Meaning |
|---|---|
| **Call** | Console call id, linked to the call record |
| **Caller** | Masked caller number |
| **Agent** | Agent attribution, or *Restricted* when you do not hold `view cases` |
| **Final verified dentist** | The dentist reached through a verified bridge, or the unverified destination label |
| **Connection** | Verified, unverified or no connected dentist |
| **Attempts** | Number of dentist transfer attempts recorded |
| **Answers** | Answered screening questions (0 when the case record is restricted) |
| **Transfer outcome** | Plain-language summary of how the transfer ended |
| **Duration** | Call length, or *Not available* |
| **State** | Interaction state |
| **Flags** | Count of correlation or telemetry exceptions on the call |

### 10.3 Export

**Export privacy-limited CSV** appears only when your role holds `export call history`. The export follows the current filters, contains masked numbers only, and writes an audit entry naming you. Standard Viewer roles do not have it.

### 10.4 Paging

Lists page through 25 calls at a time with **Previous** and **Next**. The filters stay in the URL, so a link you copy reproduces exactly the same view for a colleague with the same permissions.

## 11. Opening and reading a call record

Click any call in a list to open **Call #id**. The page header prints the connection label and the outcome, and reminds you that **all timestamps are UTC** and that a dash or *"Not available"* means the PBX never supplied that measurement.

The record is laid out in lettered sections. Every section is read-only for a Viewer.

### 11.1 A. Caller & call

| Field | Notes |
|---|---|
| Call ID | The console's stable identifier |
| Caller number | Masked, e.g. `+233241*****67`, or *Unknown* |
| Caller name | A name only when your role holds `view cases`; otherwise *Restricted / not given* |
| Inbound line | The masked DID the call arrived on |
| Agent | Agent name, or *Restricted* |
| Arrived / Ended | UTC timestamps |
| Total duration | Whole-call duration, or *Not available* |
| Call state | The interaction state |
| Consent | Latest consent status and questionnaire version, or *Not recorded* |

### 11.2 B. Dentist & transfer

| Field | Notes |
|---|---|
| Connection | Verified, unverified or no connected dentist |
| Final verified dentist | The verified dentist, the unverified destination, or *No connected dentist* |
| Destination (masked) | The masked dialled mobile number |
| Attempts | Number of attempts |
| Attempted dentists | Every dentist tried, in order; *None recorded* when the transfer was never attempted |
| Transfer completed | Timestamp of the verified completion, when present |

Read this section slowly. **A network answer is not a dentist answer.** The label *verified* appears only when the bridge evidence proves the caller and the dentist were connected. An attempt that rang, was answered by a network, or was cut short appears as *unverified* or as a failed attempt.

### 11.3 C. Questionnaire & answers

This section is only populated when your role holds `view cases`. It lists each screening version used on the call, with a table of:

| Column | Meaning |
|---|---|
| **#** | Question order |
| **Question as asked** | The prompt snapshot stored with the answer, not today's wording |
| **Saved answer** | The recorded answer |
| **Status** | `answered`, `unknown`, `not_applicable`, etc. |
| **Version** | The questionnaire version that was live at the time |
| **Saved at** | UTC timestamp |
| **Author** | Who recorded the answer |

Below the submitted screening, an **Unconfirmed draft** block may appear. A draft was autosaved but never submitted, so it is *not* part of the call record. Treat it as work in progress, not evidence.

Without `view cases` the section reads: *"No case record available for your permissions."*

### 11.4 D. Transfer attempts

Each attempt is listed in chronological order with its result. Failed attempts are never overwritten by a later successful one, so you can see the full retry chain — dentist, masked destination, dial time, ringing, answer, human acceptance, completion, end and failure reason.

### 11.5 E. Call timeline

The timeline merges PBX events into an ordered story: caller arrival, IVR steps, agent answer, holds, dial attempts, bridge, hang-up. Each entry shows its evidence source and confidence, so you can tell a measured event from a projected one.

### 11.6 F. Durations

Every duration is measured from retained evidence. Typical rows include inbound wait, agent talk, hold, dentist ringing (when observable), private consultation, caller–dentist connected time and total call time. A value the PBX never recorded is shown as *Not available* rather than zero.

### 11.7 G. Notes & audit

- **Factual notes** — the agent's notes, when your role holds `view cases`.
- **Consent history** — every consent grant or withdrawal with its questionnaire version.
- **Access and change audit** — who opened or changed the record, and when.

Opening a call record is itself audited: the console writes a `Call detail viewed` entry in the `call-history` log against your account, noting whether case content was visible to you.

### 11.8 Supporting evidence tables

Below the lettered sections the page exposes the underlying evidence, each paginated separately:

- **Legs** — every PBX channel involved, with masked caller, inbound and destination numbers;
- **Events** — the raw normalised PBX events;
- **Bridges** — bridge start/end evidence;
- **Metrics** — projected timings and their quality;
- **Exceptions** — correlation or telemetry problems recorded on this call.

If a call looks wrong, the **Exceptions** table is where you find out why.

## 12. Reading the evidence inside a call record

The console distinguishes five things that are easy to confuse. Keep them separate when you report on a call.

| Term | What it means | How it appears |
|---|---|---|
| **Arrived** | The PBX saw the caller leg | A start time on the caller leg |
| **Answered by agent** | An agent leg connected to the caller | Agent leg with an answer time |
| **Dialled** | The console or agent attempted a dentist number | A transfer attempt row |
| **Verified connection** | Bridge evidence proves caller and dentist were connected | *Connected (verified)* |
| **Unverified bridge evidence** | Some bridge evidence exists but does not prove a human dentist accepted | *Unverified* with the destination |

Two further concepts appear on the page:

- **Timing quality** — how much of the duration set was actually measured. A call with missing events shows fewer durations; that is honest, not broken.
- **Reconciliation status** — whether the call's PBX records have been matched against CDR/CEL. A call awaiting reconciliation may still gain detail later.

### 12.1 Common false conclusions

| What you see | What it does **not** mean |
|---|---|
| *No connected dentist* | That no one tried. Check the attempts table first |
| *Unverified* | That the dentist did not speak to the caller. It means the evidence is incomplete |
| *Not available* duration | That the duration was zero |
| *Menu option not recorded* | That the caller pressed nothing |
| *Restricted / not given* | That the caller withheld their name. It is a permission mask |
| A short call | That the call was mishandled. Check dispositions and the service window |

### 12.2 Evidence freshness on a single call

The call page shows the AMI evidence state, the heartbeat age, any telemetry gap that overlaps the call, and the number of unprocessed events for that PBX connection. Use it like this:

- **Fresh evidence and no gaps** — the recorded events are complete for the period.
- **A gap overlapping the call** — an event may be missing. Say "evidence gap", not "call failed".
- **Unprocessed events on the connection** — the listener saw traffic that has not yet been correlated. Give it time, then ask your manager to re-check before concluding.

---

## 13. What is masked or restricted — and why

EXP is built so that a monitoring role can review service quality without turning the console into a directory of patients and phone numbers.

| Data | How a Viewer sees it | Why |
|---|---|---|
| Caller, inbound and dentist numbers | Masked, e.g. `+233241*****67` | Numbers are stored as a fingerprint plus a masked display value |
| Caller name | *Restricted / not given* unless the role also has `view cases` | Names are encrypted personal data |
| Agent name | *Restricted* unless the role also has `view cases` | Agent attribution belongs to the documented case |
| Consent, answers, notes, dispositions | Hidden unless the role also has `view cases` | Clinical and consent content is case-scoped |
| Full phone numbers in exports | Never exported | Exports are masked by design |
| Executive summary report | Not listed | Sensitive; needs `view executive reports` |

### 13.1 If you need the full record

Do not ask a colleague to read their screen to you. Ask your manager to grant `view cases` to your role for the period you need, or to produce the evidence through the proper channel. Any change to your permissions is made in **Administration → Roles** and takes effect on your next request.

### 13.2 Searching with a number

If a caller gives you a full number and asks what happened, type it into the **Caller number** filter. The console hashes your input and matches the stored fingerprint, so you can confirm the journey without the number ever being displayed back to you.

## 14. Reports: the catalogue

**Route:** `/reports`

Reports are built only from evidence EXP already stores. Every figure carries its definition, numerator, denominator and exclusions. A KPI that cannot be measured stays empty instead of being estimated.

The catalogue is grouped by category. With `view reports`, a Viewer sees these definitions:

### 14.1 Operations

| Report | Grain | What it answers |
|---|---|---|
| **Daily operations** | Daily | Contact volume, answer performance, safety priorities and reconciliation flags for the duty supervisor |
| **Weekly operations** | Weekly | Contact handling combined with warm-transfer evidence |
| **Monthly management** | Monthly | Trends for service managers, including relief classifications and safety outcomes |

### 14.2 Transfers

| Report | Grain | What it answers |
|---|---|---|
| **Warm transfer quality** | Daily | Dentist ring, answer, handover and consultation evidence, attributed per dentist |

### 14.3 Callback follow-up

| Report | Grain | What it answers |
|---|---|---|
| **Callback follow-up** | Daily | Callback demand, completion and on-time performance against the recorded due time |

### 14.4 Quality assurance

| Report | Grain | What it answers |
|---|---|---|
| **Quality assurance** | Weekly | Finalised QA scoring, critical fails and outstanding coaching by agent |

### 14.5 Dentist engagement

| Report | Grain | What it answers |
|---|---|---|
| **Dentist engagement and cost** | Daily | Rostered dentist time, transfer volume and consultation cost when a tariff is configured |

### 14.6 Safety and incidents

| Report | Grain | What it answers |
|---|---|---|
| **Safety and incidents** | Weekly | Emergency and urgent demand, incident escalation and corrective-action verification |
| **Short and nuisance call dispositions** | Daily | Factual abandoned, silent, nuisance and ultra-short classifications, plus review demand |

### 14.7 What you will not see

The **Executive summary** is marked *Sensitive* and needs `view executive reports`. It is not listed for a Viewer and cannot be opened by guessing its URL.

### 14.8 Reading the catalogue cards

Each card shows the report name, its description, the number of KPIs, the grain, its internal key, and the latest saved run if one exists — reference, period, and revision. A card with *"No saved run for this definition yet"* is normal on a new environment.

---

## 15. Running, saving and exporting a report

Open a report to reach its working page.

### 15.1 The window

The page opens on **today** (Ghana calendar date). You can change **From** and **To** within the maximum window configured for the installation — 92 days unless your administrator has changed it. The form states the timezone and the maximum in the helper text.

### 15.2 Computing

The KPI table recomputes for the dates you submit. Nothing is stored until you press **Save run**.

### 15.3 Saving a run

**Save run** stores an immutable snapshot of the figures with:

- a reference such as `RPT-20261119-20261119-AB12CD`;
- the period, generator and generation time;
- the KPI count and the definition revision;
- a checksum of the computed rows and a revision number.

Re-running the same definition for the same period creates a **new revision** rather than overwriting the earlier one. The **Saved snapshots** table keeps the history so a figure you quoted last week can still be reproduced. Saving is audited.

### 15.4 Exporting

**Export KPI evidence (CSV)** appears only with `export reports`. It writes the evidence behind each KPI and records an audit entry. Viewer roles do not have this permission by default.

### 15.5 If the KPI table is empty

The page says: *"This definition publishes no KPI you are permitted to read."* That means the definition's KPIs are outside your permissions, not that the service had no activity. Ask your manager which grant you need.

## 16. Reading a report honestly

Every KPI row has four columns. Read all four, not just the value.

| Column | What it gives you |
|---|---|
| **KPI** | The human label, internal key and unit |
| **Value** | The computed figure, or a dash when it cannot be measured |
| **Numerator / denominator** | The counts behind the percentage or average |
| **Definition** | The rule used, plus any exclusions |

### 16.1 Percentages and averages

A percentage is only as good as its denominator. `50%` from `1/2` is not evidence of a service pattern. Always read the denominator before quoting a rate in a meeting.

### 16.2 Empty values

An empty value means the evidence was not recorded, or the denominator was zero. It never means "zero". Write "not measured" in your notes, not "0".

### 16.3 Exclusions

Many KPIs exclude specific call types — for example ultra-short contacts, internal test calls, or calls with a telemetry gap. The exclusions are printed under the definition. If you compare two reports, check that they exclude the same things.

### 16.4 Reproducibility

A saved snapshot stores its definition revision and a checksum. If a figure changes between two runs of the same period, compare the revisions first: a definition change is a legitimate reason for a different number.

### 16.5 What reports cannot tell you

- They cannot tell you **why** a caller behaved a certain way; open the call record.
- They cannot tell you whether a dentist was personally available; availability is a roster statement, and performance is observed call evidence only.
- They cannot tell you whether a call was clinically appropriate; that is the clinical reviewer's job.

---

## 17. The dentist directory (read-only)

**Route:** `/dentists`

The directory is a scheduling and contact view, not a live presence view. The page states it plainly: *"Coverage is driven by rosters and timestamped overrides; call activity is observed EXP transfer evidence only, never personal-mobile presence."*

### 17.1 The summary tiles

| Tile | Meaning |
|---|---|
| **Rostered now** | On a published duty window at this moment |
| **On break** | Inside a recorded break |
| **On leave** | Approved leave covering now |
| **Not rostered** | No published duty window now |
| **Selectable** | Currently eligible for a transfer |
| **Unmatched numbers** | Mobile destinations observed in calls that match no directory number |

### 17.2 The directory table

Each dentist row shows the name, active state, approved numbers with their labels and status, and the current roster state. As a Viewer you see the table but not the **Add dentist** or **Edit** controls; the form is hidden because your role lacks `edit dentists`.

### 17.3 Selectable dentists

The page lists dentists a transfer could currently reach. This is useful context when you review a call: if a dentist was selectable at the time, an agent had a directory entry to choose.

### 17.4 Unknown mobile destinations

A separate panel lists observed mobile numbers that do not match any approved dentist number. These are evidence gaps in the directory, not necessarily errors in the call. Report persistent entries to your manager so the directory can be corrected.

### 17.5 Related read-only screens

Five further dentist screens are published at `/dentists/numbers`, `/dentists/credentials`, `/dentists/rosters`, `/dentists/availability` and `/dentists/performance`. They are separate screens rather than tabs on the directory; your manager can give you the direct link. They are described in §18 and §19.

## 18. Dentist numbers and credentials

### 18.1 Dentist numbers

**Route:** `/dentists/numbers`

Approved Ghana mobile numbers stored in E.164 form. Dialled mobile channels are normalised before matching, so roster attribution never depends on how the operator wrote the number.

What to read:

- which number belongs to which dentist, and whether it is **active** or **retired**;
- the label (for example *Mobile* or *Backup*) and the current status;
- any number that has been replaced, so you do not attribute an old call to the wrong person.

As a Viewer you cannot add or edit a number. If a call appears attributed to the wrong dentist, report it with the call id; do not assume the agent chose incorrectly.

### 18.2 Dentist credentials

**Route:** `/dentists/credentials`

Licence, registration and indemnity tracking. Validity is derived from the expiry date, so a lapsed credential is never reported as current.

What to read:

- the credential type and reference;
- the expiry date and the derived validity state;
- dentists with credentials expiring soon or already expired.

Credentials are shown so that a reviewer can confirm the directory is governing a compliant roster. If you spot an expired credential on a dentist who was selectable, raise it with your manager — that is a governance issue, not a call-handling issue.

---

## 19. Dentist rosters, availability and performance

### 19.1 Rosters

**Route:** `/dentists/rosters`

Published shifts, cover and approved leave. A dentist is selectable only inside a published duty window and without a blocking override.

What to read:

- the published duty windows by day;
- approved leave and cover;
- the roster state the console derives from them.

Use rosters to answer: *"Should this dentist have been reachable at 11:20 on Tuesday?"* A dentist outside a published window is not expected to be selectable, even if a transfer was attempted.

### 19.2 Availability

**Route:** `/dentists/availability`

Timestamped manual overrides layered over the rosters — a break, an early finish, a return from leave.

Read them as **scheduling statements**. They are not proof that a personal mobile was reachable, and the console says so on the page.

### 19.3 Performance

**Route:** `/dentists/performance`

Outcomes for mobile legs that match a directory number: dials, answers, verified bridges, failed attempts and timings.

Read them as **observed EXP call attempts**, not as a measure of personal-mobile availability. A dentist with no calls in a period may simply not have been selected; it does not by itself prove unreachability. Combine this screen with the roster and availability records before drawing a conclusion.

## 20. Privacy, audit and retention

### 20.1 What is stored

- **Phone numbers** as a fingerprint plus a masked display value.
- **Caller names and clinical text** encrypted at rest and scoped by permission.
- **Call evidence** — legs, events, bridges, metrics and exceptions — tied to the interaction.
- **Audit entries** for call-detail views, exports and administration changes.

### 20.2 What is audited when you read

| Action | Audit entry |
|---|---|
| Open a call record | `Call detail viewed` in the `call-history` log, with whether case content was visible |
| Export call history *(if granted)* | Filter set and format in the `call-history` log |
| Export report KPI evidence *(if granted)* | An audit entry naming you |
| Save a report run | The run records you as its generator |

Audit entries are not a punishment; they are how the service proves who saw what. Read records because you need them, not out of curiosity.

### 20.3 Retention

The service keeps call evidence for the period set by your organisation's retention policy. When a record is pruned, it is gone from every screen. A report snapshot keeps the computed figures even after the underlying call records are pruned, which is one reason snapshots are checksummed.

### 20.4 Handling what you see

- Do not copy caller names, numbers or clinical content into personal notes, chat or email.
- If you must share a call with a colleague, send the **call id** and let them open it with their own permissions.
- Screenshots of a call record contain restricted content; treat them as confidential.
- If you receive a subject-access or complaint request, route it to your manager rather than answering from the screen.

---

## 21. Troubleshooting

| Symptom | Likely cause | What to do |
|---|---|---|
| **403 Forbidden** | Your role lacks the permission for that page | Use the menus; ask your manager for the grant if the work requires it |
| **404 Not found** on a call record | The call is outside your scope | Check the Scope filter; ask your manager to widen the grant |
| Caller name shows **Restricted / not given** | You do not hold `view cases` | Expected for a Viewer; ask for the grant if needed |
| Questionnaire section says **No case record available for your permissions** | Same as above | Read the telephony evidence; request the grant for clinical content |
| A duration shows **Not available** | The PBX never supplied the measurement | Record it as not measured, not as zero |
| **PBX stale** / **PBX offline** badge | Live telemetry has stopped | Tell your manager; stored history is still readable |
| *Menu option not recorded* | The key press was not captured | Quote it as an evidence gap |
| Reports page has no Executive summary | It needs `view executive reports` | Expected for a Viewer |
| **Export** buttons are missing | Export permissions are not granted | Ask your manager; do not ask a colleague to export for you |
| A report shows *"no KPI you are permitted to read"* | Your grants do not cover that definition | Ask which grant is needed |
| Signed out unexpectedly | Session terminated or expired | Sign in again; if it repeats, ask your administrator |
| A guide link is missing from the menu | Your role lacks `view … guides` | Expected; the controller enforces the same rule |

### 21.1 When something looks wrong in the data

1. Note the **call id**, the **time** and what you expected to see.
2. Check the **Exceptions** table and the **Evidence freshness** panel on the call.
3. Check the **service window** and the **roster** for the same period.
4. Send the call id and your notes to your manager. Do not attempt to correct the record.

---

## 22. Daily and weekly checklist

### 22.1 Start of a review session

- [ ] Sign in with 2FA and confirm the Dashboard loads.
- [ ] Check the service and PBX badges on the first call screen you open.
- [ ] Note any **PBX stale** or **offline** state and tell your manager.
- [ ] Decide which screen answers today's question (§7).

### 22.2 End of a review

- [ ] Record the call ids you relied on, not screenshots of personal data.
- [ ] Note any report reference and revision you quoted.
- [ ] Sign out if the workstation is shared.

### 22.3 Weekly

- [ ] Review the saved report runs for the week and check for unexplained revisions.
- [ ] Review unmatched mobile destinations in the dentist directory.
- [ ] Review credentials nearing expiry.
- [ ] Confirm your own access still matches your job — no more, no less.

## 23. Glossary

| Term | Meaning |
|---|---|
| **AMI** | Asterisk Manager Interface, the read-only telemetry feed from the PBX |
| **Bridge** | The PBX action that joins the caller and the dentist audio paths |
| **Call record** | The console's detail page for one interaction |
| **Case** | The documented side of a call: consent, caller details, answers, notes, disposition |
| **CDR / CEL** | PBX call detail and event logs used for reconciliation |
| **Correlation** | Matching PBX legs and events into one interaction |
| **DID** | The published inbound number callers dial |
| **Disposition** | The outcome an agent records for the case |
| **Evidence freshness** | How recent the AMI heartbeat and events are |
| **Interaction** | One caller journey through the line, from arrival to hang-up |
| **IVR** | The menu the caller hears before reaching an agent |
| **KPI** | A defined, reproducible measure with numerator, denominator and exclusions |
| **Leg** | One channel in the PBX for a call (caller leg, agent leg, dentist leg) |
| **Masked number** | A display value such as `+233241*****67`; the full number is not stored |
| **Reconciliation** | Checking PBX events against CDR/CEL and flagging differences |
| **Roster** | The published duty windows for a dentist |
| **SBAR** | Situation, Background, Assessment, Recommendation — the handover format |
| **Snapshot (report run)** | An immutable, checksummed copy of a report's KPIs for a period |
| **Timing quality** | How much of the duration set was actually measured |
| **Unverified connection** | Bridge evidence exists but does not prove a human dentist accepted |
| **Verified connection** | Bridge evidence proves the caller and dentist were connected |

---

## 24. Quick reference card

| Task | Where |
|---|---|
| See today's counters | Calls → Agent calls or DID journeys |
| Find what a caller pressed | Calls → DID journeys |
| Find calls with no verified dentist | Calls → All calls, Connection = *No connected dentist* |
| Investigate one call | Click the call id → read sections A–G |
| Explain a missing number | Section D (attempts) + Exceptions table |
| Check a weekly trend | Reports → Weekly operations |
| Check dentist coverage | Dentists + Rosters + Availability |
| Check an evidence gap | Call record → Evidence freshness / Exceptions |
| Open this manual | Sidebar footer → Viewer guide |
| Ask for more access | Your manager; grants live in Administration → Roles |

### 24.1 The five rules of a Viewer

1. **Read the label.** Verified is not the same as answered; unverified is not the same as failed.
2. **Empty is not zero.** *Not available* means the PBX did not measure it.
3. **Masked is not missing.** *Restricted* is a permission decision.
4. **Quote the call id**, not the personal data.
5. **Never guess.** If the evidence is not there, say so.

---

*End of the Viewer role guide. The Agent role guide covers the call-centre workflow from answering the line to closing the case, and is only visible to accounts that hold `view agent guides`.*











