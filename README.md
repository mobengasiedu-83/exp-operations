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
| Server-driven UI bridge | Inertia Laravel 3 |
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

## Requirements

- **PHP 8.3+** with `pdo_mysql` (or `pdo_sqlite`), `mbstring`, `openssl`, `json`, `curl`, `tokenizer`, `xml`
- **Composer 2**
- **Node.js 22+** and npm (CI runs Node 22)
- **MySQL 8+** for a real installation; SQLite is enough to boot the UI or run the suite
- Optional: **Redis** (configured through `predis/predis`), a reachable **VitalPBX AMI** endpoint for live telemetry, and a supervised process manager for the listener
- Optional: a separate HTTPS **call-control gateway** when browser call controls are enabled (off by default)

## Getting started

### 1. Install dependencies

```bash
git clone <repository-url> exp-operations
cd exp-operations
composer install
cp .env.example .env
php artisan key:generate
```

`composer setup` wraps the whole bootstrap (`composer install`, `.env`, app key, `npm install`, `npm run build` and a migration run) if you just want a throwaway local boot on the example SQLite database. For a real environment, follow the steps below and configure the database before migrating.

### 2. Configure the environment

Edit `.env` before the first migration:

```dotenv
APP_NAME="EXP Operations Console"
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=exp_operations
DB_USERNAME=root
DB_PASSWORD=
```

Keep `APP_URL` in step with the host you actually serve from: it drives generated links and the asset base.

### 3. Migrate and seed

```bash
php artisan migrate --force
php artisan db:seed --force
```

`php artisan db:seed` publishes everything the console needs to be usable:

- every permission and the base role matrix (`DatabaseSeeder`)
- the versioned screening protocol `exp-2026-11-19-v1` with its ten questions (`ScreeningProtocolSeeder`)
- the dentist directory and approved numbers (`DentistDirectorySeeder`)
- the report catalogue (`ReportCatalogueSeeder`)
- role manuals and the read-only **Viewer** role (`GuideAccessSeeder`)
- workspace, transfer, follow-up, knowledge-bank, QA/training and guide permissions
- standard service hours (Mon–Fri, 10:00–14:00), settings and live-alert rules

The seeded `admin@exp-operations.local` account is created **without a usable password** (`Str::random(64)`). Set a known password during provisioning, for example:

```bash
php artisan tinker --execute '$u = App\Models\User::where("email", "admin@exp-operations.local")->firstOrFail(); $u->password = Hash::make("choose-a-strong-password"); $u->save();'
```

On later deployments, re-run only the narrow seeders you need, e.g.
`php artisan db:seed --class=GuideAccessSeeder --force --no-interaction`.

### 4. Build the frontend

```bash
npm run build     # production assets (also generates Wayfinder route helpers)
npm run dev       # or: Vite dev server with HMR
```

`resources/js/routes` and `resources/js/actions` are generated and git-ignored — they are produced by Wayfinder during `npm run dev` / `npm run build`. If the UI throws “Unable to locate file in Vite manifest”, the assets have not been built yet.

### 5. Run the application

```bash
composer run dev
```

This runs `php artisan dev`, which starts the HTTP server, the queue listener, the log tail and the Vite dev server together (`composer run dev -- --inline` gives plain log output in non-TTY environments). Alternatively run the pieces by hand:

```bash
php artisan serve
php artisan queue:listen
npm run dev
```

Broadcasting is configured for Reverb. Push updates stay silent until you run `php artisan reverb:start`; the live screens also poll, so nothing breaks if Reverb is off during UI work.

### 6. Attach the PBX (live telemetry only)

```bash
php artisan pbx:status          # show configured connections and last observed state
php artisan pbx:connect         # open a short-lived diagnostic connection
php artisan exp:ami-listen      # long-running, single-instance, read-only listener
```

Only **one** listener may run per deployment; it writes a lock file and reports a heartbeat. Start it under your process manager (Supervisor/systemd), not in a terminal session you might close.

### Useful commands

| Command | Purpose |
|---|---|
| `composer run dev` | App server + queue listener + Vite |
| `composer test` | Clear config, Pint check, PHPStan, full Pest suite |
| `composer lint` / `composer lint:check` | Format / verify PHP style with Pint |
| `composer types:check` | Larastan static analysis |
| `composer ci:check` | Frontend check, TypeScript check and tests (what CI runs) |
| `npm run check` / `npm run check:fix` | Format + lint the frontend |
| `npm run types:check` | `vue-tsc --noEmit` |
| `php artisan schedule:work` | Run the scheduler locally |

## Configuration reference

All EXP settings live in `config/services.php` (`exp_pbx`, `exp_call_control`), `config/exp_service.php`, `config/exp_ivr.php`, `config/reporting.php`, `config/horizon.php` and `config/reverb.php`. The defaults below come from `.env.example` and those config files; run `php artisan config:clear` after changing any of them.

### PBX and listener

| Variable | Default | Meaning |
|---|---|---|
| `EXP_PBX_NAME` | `VitalPBX EXP` | Connection name stored with every captured event |
| `EXP_PBX_HOST` / `EXP_PBX_AMI_PORT` | – / `5038` | AMI endpoint |
| `EXP_PBX_AMI_USERNAME` / `EXP_PBX_AMI_PASSWORD` | – | AMI credentials (read-only account) |
| `EXP_PBX_AMI_TIMEOUT` | `10` | AMI socket timeout (seconds) |
| `EXP_PBX_INBOUND_DIDS` | `0596921095` | Approved entry identifiers |
| `EXP_PBX_INBOUND_CONTEXTS` | `incoming-calls` | Allowed inbound contexts; wildcards allowed |
| `EXP_PBX_IVR_CONTEXTS` | `IVR-*,app-ivr,app-announcement` | Contexts where key presses are captured |
| `EXP_PBX_IGNORED_EVENTS` | built-in noise list | AMI events that carry no journey evidence |
| `EXP_PBX_LOG_EACH_EVENT` | `false` | Per-event logging (diagnostics only) |
| `EXP_PBX_RECONCILIATION_TOLERANCE_SECONDS` | `2` | Timing tolerance against CDR/CEL |
| `EXP_PBX_HEARTBEAT_INTERVAL_SECONDS` | `20` | Listener heartbeat cadence |
| `EXP_PBX_HEARTBEAT_TIMEOUT_SECONDS` | `45` | Listener reconnect timeout |
| `EXP_PBX_PRUNE_INTERVAL_SECONDS` | `60` | Retention sweep cadence |
| `EXP_PBX_RETENTION_DAYS` / `EXP_PBX_MAX_RECORDS` | `7` / `100000` | Telemetry retention window and row ceiling |
| `EXP_AMI_HEARTBEAT_STALE_SECONDS` | `90` | Age at which the AMI heartbeat is flagged stale |
| `EXP_AMI_LISTENER_LOCK_PATH` | `storage/framework/exp-ami-listener.lock` | Single-instance lock file |

### Live operations, dentists and service window

| Variable | Default | Meaning |
|---|---|---|
| `EXP_LIVE_REFRESH_SECONDS` | `3` | Live snapshot polling interval |
| `EXP_LONG_RINGING_SECONDS` | `45` | Threshold for the “dentist ringing too long” alert |
| `EXP_ALERT_LOOKBACK_MINUTES` | `1440` | Alert evaluation window |
| `EXP_AGENT_PRESENCE_WINDOW_MINUTES` | `10` | Agent presence window |
| `EXP_DENTIST_UNMATCHED_LOOKBACK_DAYS` | `7` | Window for unmatched dialled destinations |
| `EXP_DENTIST_PERFORMANCE_WINDOW_DAYS` | `30` | Dentist performance roll-up window |
| `EXP_SCREENING_EXTENSION` | `204` | Screening extension in the PBX dialplan |
| `EXP_IVR_AGENT_OPTION` | `3` | The only IVR option that reaches an agent |
| `EXP_IVR_USER_EVENT` | – | Optional `UserEvent` name carrying the selection |
| `EXP_SERVICE_TIMEZONE` | `Africa/Accra` | Published service timezone |
| `EXP_SERVICE_OPENS_AT` / `EXP_SERVICE_CLOSES_AT` | `10:00` / `14:00` | Published service window |

Ghana public holidays, Eid dates and one-off overrides are code-owned in `config/exp_service.php`; publish confirmed Eid/Shaqq dates before generating a new year’s rosters.

### Call control, reporting, real-time

| Variable | Default | Meaning |
|---|---|---|
| `EXP_CALL_CONTROL_ENABLED` | `false` | Enable browser call controls |
| `EXP_CALL_CONTROL_LAB_APPROVED` | `false` | Second gate before production use |
| `EXP_CALL_CONTROL_URL` / `_TOKEN` / `_IDENTITY` | – | HTTPS control gateway, token and identity |
| `REPORTING_CONSULTATION_TARIFF_PER_MINUTE` | `0` | Consultation tariff used for the cost KPI only |
| `REPORTING_MAX_WINDOW_DAYS` | `92` | Maximum manual report window |
| `REVERB_*` / `VITE_REVERB_*` | – | Reverb broadcasting credentials |
| `HORIZON_*`, `config/horizon.php` | – | Queue supervisors and workers |

Feature flags are stored in the `settings` table (`app.feature_flags.ami_listener`, `app.feature_flags.reverb_push`) and can be changed from **Administration → Service settings**.

## Screens and modules

| Screen | Route name | Path |
|---|---|---|
| Welcome | `home` | `/` |
| Dashboard (live snapshot) | `dashboard` | `/dashboard` |
| Agent workspace | `workspace.index` | `/workspace` |
| Cases list / case detail | `cases.index` / `workspace.show` | `/cases`, `/cases/{case}` |
| Shift handover and assignments | `cases.handover*`, `cases.assignments*` | `/cases/handover`, `/cases/assignments` |
| Call control panel | `call-control.show` | `/cases/{case}/call-control` |
| All calls (reconciled history) | `calls.index` | `/live-operations/calls` |
| Call detail | `calls.show` | `/live-operations/calls/{interaction}` |
| Call history export | `calls.export` | `/live-operations/calls/export` |
| Agent calls / DID journeys | `calls.agent` / `calls.did` | `/calls/agent`, `/calls/did` |
| Active calls / transfers / alerts | `live-operations.*` | `/live-operations/active-calls`, `/live-operations/transfers`, `/live-operations/alerts` |
| Transfers and attempt detail | `transfers.*` | `/transfers`, `/transfers/{attempt}` |
| Callbacks and referrals | `callbacks.*`, `referrals.*` | `/callbacks`, `/referrals` |
| Dentist directory and sub-screens | `dentists.*` | `/dentists`, `/dentists/{availability,credentials,numbers,performance,rosters}` |
| Knowledge bank | `knowledge-bank.*` | `/knowledge-bank`, `/knowledge-bank/offline` |
| Quality assurance / training / incidents | `quality-assurance.*`, `training.*`, `incidents.*` | `/quality-assurance`, `/training`, `/incidents` |
| Reports catalogue and detail | `reports.*` | `/reports`, `/reports/{report}` |
| Questionnaire versions | `screening-protocols.*` | `/screening-protocols` |
| Role guides | `guides.*` | `/guides`, `/guides/{guide}` (`agent`, `viewer`, `super-admin`) |
| Administration hub and `admin/*` tools | `administration.index`, `admin.*` | `/administration`, `/admin/...` |
| Diagnostics | `correlation-exceptions.index` | `/diagnostics/correlation-exceptions` |
| Personal settings | `profile.*`, `security.*` | `/settings/profile`, `/settings/security` |

## Roles and permissions

Authorisation is enforced twice: the sidebar (`App\Services\PrimaryNavigation`) only renders menus the user can open, and controller actions guard their work with `abort_unless($user->can(...), 403)` or `$this->authorize(...)` before touching data. Inertia shares the resolved permission list with the frontend so buttons can be hidden, but hiding is never the only check.

The seeders publish **46 base permissions plus three guide permissions**, and these roles:

| Role | Scope |
|---|---|
| **Super Administrator** | All base permissions, plus all three role manuals |
| **Administrator** | All base permissions; agent and viewer manuals only (never the Super Administrator manual) |
| **Agent** | `view cases`, `edit cases`, `control calls`, `view call history`, `view dentists` + agent guide |
| **Supervisor** | Agent plus `view all cases`, `review cases`, `view reports`, `view audit` |
| **Viewer** | Read-only: `view call history`, `view all cases`, `view reports`, `view dentists` + viewer guide. Deliberately **without** `view pbx`, which would also expose the live console and the administration hub |
| Platform Manager | Day-to-day platform operations: users, settings, dentists, cases, knowledge bank, training, incidents |
| Lead Dentist / Clinical Supervisor | Clinical governance: cases, protocols, dentist records, knowledge approval, QA approval, live-call authorisation |
| Dental Assistant | Case support, callbacks, referrals, knowledge and training (read-only elsewhere) |
| Dentist | `view cases`, `view dentists` |
| QA Reviewer | Audit, reports, QA reviews and approvals, training, incidents |
| Executive / Management | Read-only reporting, including `view executive reports` and `export reports` |

There is **no self-registration**: Fortify is configured with password reset, email verification, two-factor authentication and passkeys only. Accounts are created by an administrator, and inactive users are stopped by the `EnsureUserIsActive` middleware.

## PBX telemetry, commands and scheduler

EXP never writes to the PBX. It reads AMI frames, imports CDR/CEL and IVR exports read-only, and reconciles the two so a call record can state what is evidenced and what is missing.

| Command | Purpose |
|---|---|
| `exp:ami-listen` | Long-running capture of AMI events. Single instance per deployment; writes a heartbeat and prunes old telemetry |
| `exp:correlate-calls` | Replay a bounded batch of AMI events (`--connection`, `--since`, `--after`, `--limit`) or rebuild durable timelines (`--rebuild`) |
| `exp:reconcile-calls` | Compare completed call legs with connection-scoped CDR/CEL evidence (`--pending` for the queued set) |
| `exp:import-call-records` | Import a read-only CDR/CEL export with timezone validation and replay protection |
| `exp:import-ivr-records` | Backfill IVR selections using exact PBX leg IDs, without inferring outcomes |
| `exp:prune-telemetry` | Apply AMI/log retention window and row limits |
| `exp:evaluate-alerts` | Evaluate live-operations alert rules and reconcile open alerts |
| `exp:escalate-callbacks` | Escalate callbacks that missed their follow-up SLA |
| `exp:sync-transfers` | Rebuild transfer tracking from projected telemetry |
| `pbx:status`, `pbx:connect`, `pbx:reconnect`, `pbx:disconnect` | Connection diagnostics |
| `config:exp-pbx:*` | Inspect and manage stored AMI connection records (`list`, `get`, `set`, `delete`, `clear`) |

Scheduled work (`routes/console.php`):

| Task | Cadence |
|---|---|
| `exp:reconcile-calls --pending` | Every minute |
| `exp:evaluate-alerts` | Every minute |
| `exp:escalate-callbacks` | Every five minutes |
| `horizon:snapshot` | Every five minutes |
| `exp:prune-telemetry` | Hourly |

Run `php artisan schedule:work` locally and a supervised `php artisan schedule:run` cron (or `schedule:work` service) in production.

## Testing and quality checks

The suite is Pest 4 and runs against an in-memory SQLite database with array cache/session and sync queue (`phpunit.xml` forces these, so an exported `DB_*` in your shell cannot point tests at your real database).

```bash
php artisan test --compact                        # whole suite
php artisan test tests/Feature/CallJourneyAccessTest.php
php artisan test --filter="call history shows masked caller"
vendor/bin/pest tests/Feature/InboundCallJourneyTest.php
```

Latest verified run (20 November 2026): **417 tests, 415 passed, 2 skipped, 3,478 assertions** in ~96 seconds. The two skips are the registration tests, because self-registration is not enabled in `config/fortify.php`.

| Command | Checks |
|---|---|
| `composer test` | `config:clear`, Pint `--test`, Larastan, full Pest suite |
| `composer lint` / `vendor/bin/pint --dirty --format agent` | PHP formatting |
| `composer types:check` | Larastan / PHPStan analysis of `app/` |
| `npm run check` | Frontend format + lint (`vp check`) |
| `npm run check:fix` | Same, with fixes applied |
| `npm run types:check` | `vue-tsc --noEmit` |

CI lives in `.github/workflows/tests.yml`: it runs `composer setup` then `composer ci:check` on every push to `main` and every pull request. Run the narrowest test file that covers your change, and add a feature test whenever behaviour changes.

## Project structure

```
app/
  Console/Commands/         EXP and PBX artisan commands
  Http/Controllers/         Module controllers (+ Admin/ for the admin tools)
  Http/Middleware/          Inertia shared props, active-user, administration audit, case-history guard
  Models/                  60 Eloquent models (calls, legs, cases, dentists, reports, QA, training…)
  Services/                 34 domain services (registration, correlation, lifecycle, reporting, rosters…)
config/                     exp_service.php, exp_ivr.php, reporting.php, horizon.php, reverb.php
database/
  factories/                Model factories used by tests
  migrations/               44 migrations
  seeders/                  Permissions, roles, protocol, dentists, reports, guides
docs/                       Roadmap, rollout steps and the three role manuals
resources/js/
  pages/                    57 Inertia screens (Calls, Workspace, LiveOperations, Dentists, Admin…)
  components/exp/           EXP presentation components (PageHero, StatTile, JourneyTrack)
  components/ui/            shadcn-vue primitives
  layouts/, composables/, lib/, types/
routes/web.php              144 application routes (including 27 admin routes)
tests/Feature, tests/Unit  Pest suites
```

Frontend conventions used throughout the call screens:

- Inertia pages live in `resources/js/pages` and use the `AppLayout` / `AppSidebarLayout` shells.
- Cards follow the `exp/*` language: tonal gradient headers, `PageHero` for page headers, `StatTile` for headline figures, `Button` from `components/ui` for actions.
- Route URLs come from Wayfinder helpers (`@/routes/...`, `@/actions/...`), never hand-written strings.
- Icons come from Lucide; utilities are Tailwind 4 classes with `cn()` from `@/lib/utils`.
- Live screens poll through `useLiveSnapshot` and honour `EXP_LIVE_REFRESH_SECONDS`.

## Documentation

| Document | Contents |
|---|---|
| [`docs/EXP_Simplified_Implementation_Phases_Final_No_Approvals_4_Roles.md`](docs/EXP_Simplified_Implementation_Phases_Final_No_Approvals_4_Roles.md) | The six-phase delivery roadmap and the call-detail specification |
| [`docs/EXP_Simplified_Call_Transfer_Blueprint_Final_No_Approvals_4_Roles.docx`](docs/EXP_Simplified_Call_Transfer_Blueprint_Final_No_Approvals_4_Roles.docx) | The original call-transfer blueprint (Word) the roadmap implements |
| [`docs/EXP_Remaining_Implementation.md`](docs/EXP_Remaining_Implementation.md) | Current phase audit, operating hours and roster rules, IVR backfill procedure, rollout checklist |
| [`docs/EXP_Call_Journey_Rollout_Steps.md`](docs/EXP_Call_Journey_Rollout_Steps.md) | Short pointer to the current rollout and acceptance steps |
| [`docs/EXP_Agent_User_Manual.md`](docs/EXP_Agent_User_Manual.md) | Agent guide (also rendered in-app at **User guides**) |
| [`docs/EXP_Viewer_User_Manual.md`](docs/EXP_Viewer_User_Manual.md) | Read-only viewer guide |
| [`docs/EXP_Super_Admin_User_Manual.md`](docs/EXP_Super_Admin_User_Manual.md) | Administration and configuration guide |
| [`AGENTS.md`](AGENTS.md) | Engineering conventions for contributors and AI agents (Laravel, Inertia/Vue, Pest, Pint) |

## Operational notes and open gates

- **Implemented is not accepted.** Application code, automated tests and local rollout are separate from acceptance on the real PBX. Controlled-call validation, the transfer lab matrix, Ubuntu deployment with TLS/MFA/backups, privacy and retention sign-off, and the pilot remain open (see `docs/EXP_Remaining_Implementation.md`).
- **Browser call controls are off** by default (`EXP_CALL_CONTROL_ENABLED=false`, `EXP_CALL_CONTROL_LAB_APPROVED=false`). The Jitsi/manual attended transfer is the supported path until the control gateway passes the lab gate.
- **One listener only.** Never start a second `exp:ami-listen`; the lock file and heartbeat exist to make duplication visible.
- **Missing evidence stays missing.** PBX “Answered” does not prove a human answered, and call screens must show “Unverified” or “No connected dentist” rather than inferring a connection.
- **Privacy.** Caller identities are masked where the permission set requires it, exports are audited and permission-gated, and incident evidence access is recorded.
- **Service hours are shared state.** `config/exp_service.php`, `ServiceCalendar` and `RosterPlanner` drive the banners and standard rosters; VitalPBX time conditions must be configured separately to match.
- **Automatic PBX export delivery is not implemented.** CDR/CEL and IVR evidence is imported by an operator with the commands above.

---

Maintained by the EXP operations team. For deployment and acceptance steps, start with `docs/EXP_Remaining_Implementation.md`.
