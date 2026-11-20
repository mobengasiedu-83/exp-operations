# EXP Operations Console

Agent call handling and dentist transfer console for the **Pepsodent “Talk to a Dentist”** line in Ghana.

EXP follows a call from the VitalPBX inbound leg, through the agent’s consent and screening questionnaire, the warm transfer to a dentist, and the evidence-backed call record afterwards. Every screen is permission-filtered, and every claim on a call record is tied to PBX evidence rather than assumption: an unanswered mobile leg is never shown as a connected dentist.

- **Application:** `exp-operations` (Laravel 13 · Inertia 3 · Vue 3 · TypeScript)
- **Service window:** Monday–Friday, 10:00–14:00 `Africa/Accra`, excluding Ghana public holidays
- **Landing page after login:** Dashboard (live operations snapshot)

---

## Contents

1. [What the application does](#what-the-application-does)
2. [The call journey](#the-call-journey)
3. [Tech stack](#tech-stack)
4. [Requirements](#requirements)
5. [Getting started](#getting-started)
6. [Configuration reference](#configuration-reference)
7. [Screens and modules](#screens-and-modules)
8. [Roles and permissions](#roles-and-permissions)
9. [PBX telemetry, commands and scheduler](#pbx-telemetry-commands-and-scheduler)
10. [Testing and quality checks](#testing-and-quality-checks)
11. [Project structure](#project-structure)
12. [Documentation](#documentation)
13. [Operational notes and open gates](#operational-notes-and-open-gates)

---

## What the application does

| Area | What it covers |
|---|---|
| **Agent workspace** | Associate the incoming call with the agent, capture consent, caller details and the safety screen, complete the versioned 10-question screening protocol, record an SBAR handover, select a dentist and record the transfer outcome. Questionnaire autosave and draft recovery. |
| **Call history** | Agent calls, DID journeys and the reconciled “All calls” list, with masked caller identity and permission-gated CSV export. |
| **Call detail** | One page per interaction: timestamps, agent, questionnaire version with every saved answer, consent, safety escalation, all dentist attempts in order, PBX event timeline with measured durations, notes and audit metadata. |
| **Live operations** | Active calls, in-flight transfers and alert events (AMI stale, long ringing, failed transfer), polled at `EXP_LIVE_REFRESH_SECONDS` (default 3s), with acknowledge actions. |
| **Dentist directory** | Dentists, approved E.164 mobile numbers and primary destinations, credentials with expiry, availability overrides, published rosters and observed performance. |
| **Follow-up** | Callbacks with attempts and SLA escalation, plus referrals with outcome and cancellation tracking. |
| **Knowledge bank** | Versioned articles, translations with approval workflow, offline copies and retirement. |
| **Quality and people** | QA reviews with calibration and coaching, training modules, assessments and certifications, and the incident register with corrective actions and restricted evidence access. |
| **Reports** | Ten code-owned report definitions (daily/weekly/monthly operations, transfer quality, callback follow-up, QA, dentist engagement, safety, short calls, executive summary) with saved run snapshots and audited CSV export. |
| **Administration** | Users, roles, permission catalogue, active sessions, service hours and settings, PBX connections, AMI health, integration and structured logs, CDR/CEL evidence and the audit log. |
| **Guides** | The Agent, Viewer and Super Administrator manuals rendered in-app, each gated by its own permission. |

## The call journey

```
Incoming DID
   → Agent answers (handset or Jitsi)
   → Consent recorded
   → Caller details + safety screen
   → 10-question screening protocol (versioned snapshot)
   → Dentist selected from the approved directory
   → Caller on hold, dentist mobile dialled
   → Private briefing
   → Transfer completed
   → Caller–dentist bridge (verified, or explicitly unverified)
   → Call detail holds every answer, attempt, timestamp and duration
```

One call has one canonical interaction ID, one agent, one saved questionnaire version, many answers, zero or more dentist attempts, and **at most one final verified connected dentist**. A failed attempt never overwrites an earlier one.

## Tech stack

| Layer | Choice |
|---|---|
| Runtime | PHP 8.3, Composer 2 |
| Framework | Laravel 13 (`laravel/framework` 13.43 installed) |
| Server-side rendering bridge | Inertia Laravel 3 |
| Frontend | Vue 3.5, TypeScript 5, Vite (`vite-plus`) |
| Styling | Tailwind CSS 4, shadcn-vue components (`components/ui`, new-york style), Lucide icons |
| Data | MySQL 8 (application), SQLite `:memory:` (tests), `database` queue/cache/session drivers |
| Auth | Laravel Fortify 1 (login, password reset, email verification, 2FA, passkeys); no self-registration |
| Authorisation | `spatie/laravel-permission` 8 |
| Audit | `spatie/laravel-activitylog` 4 |
| Queues | Laravel Horizon 5 |
| Real-time | Laravel Reverb 1 (broadcasting), plus polling fallbacks |
| Typed routes | Laravel Wayfinder (generates `resources/js/routes` and `resources/js/actions` at build time) |
| Exports/charts | `maatwebsite/excel` 4, Chart.js 4 with `vue-chartjs` |
| Testing | Pest 4 with `pest-plugin-laravel`, Laravel factories |
| Static analysis / style | Larastan (PHPStan), Laravel Pint |

<!-- NEXT -->
