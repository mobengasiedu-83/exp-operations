# EXP remaining implementation and rollout

Reviewed: 20 November 2026. This audit distinguishes application code, local rollout and acceptance on the real PBX. Screenshots are evidence of observed behavior, not instructions or proof of completed phases.

## Phase audit

| Phase | Implemented | Remaining work and how to apply it |
|---|---|---|
| 1: Foundation | Permission-filtered navigation, dentist directory, versioned questionnaire, role guide library and Viewer seeder | Run `php artisan db:seed --class=GuideAccessSeeder --force --no-interaction` on each deployment. Assign Viewer only to intended users. Supervisor still has edit/review/control permissions; narrow them if the target monitoring-only matrix is required. Legacy roles remain. |
| 2: Agent workspace | Call association, questionnaire snapshots/autosave, consent, safety warnings and recovery | Confirm a real call appears on the correct agent extension; answer in the handset/Jitsi, associate the correct call, record all answers and verify saved detail after reload. |
| 3: Call history/detail | Caller masking, answers, attempts, timeline, durations, permission-filtered history/export | Verify identities and two successive dentist attempts against PBX evidence. Viewer currently has `view all cases`: this is all permitted history, not an assignment-based scope. Implement assignment-based filtering before giving Viewer a narrower assigned-record remit. |
| 4: PBX evidence | AMI capture/reconnect, correlation, bounded replay, CDR/CEL reconciliation, outage flags, IVR projection and exact caller-leg IVR export import | Restart the deployed listener, replay retained evidence, import missing IVR records and run the controlled-call matrix below. Automatic recurring PBX IVR/CDR/CEL export delivery is not implemented. |
| 5: Browser controls | Command/state machine, separate gateway client, ownership/safety checks and manual fallback | Supply and configure a real HTTPS control gateway. In a lab, validate answer, hold/resume, no-answer recovery, private briefing, transfer, agent release, Local-channel/masquerade behavior and continuity. Keep browser controls disabled until that gate passes. |
| 6: Reports/production | Report catalogue, reproducible snapshots and audited CSV export | Configure Ubuntu process supervision, scheduler, TLS, privileged MFA, backups/restore, retention and privacy settings. Run the real-call pilot and restore drill. Reports are manual; scheduled generation, subscriptions and delivery remain future work. |

No phase requiring a real handset, gateway, production host or restore drill is accepted merely because automated tests pass. The older roadmap's claims that Viewer is missing are superseded by `GuideAccessSeeder`; its other live acceptance gates remain open.

## Operating hours and roster

Service is Monday–Friday, **10:00 inclusive to 14:00 exclusive, Africa/Accra**, excluding Ghana public holidays. `config/exp_service.php`, `ServiceCalendar` and `RosterPlanner` are the shared source for banners and standard roster generation.

The holiday calendar now accounts for declared 2026 observance changes, Shaqq Day and its Monday observance, and removes 4 August as a service closure. Future lunar dates are not treated as confirmed. Before generating future-year rosters, publish confirmed Eid/Shaqq dates in `exp_service.eid`, and add declared substitutes or one-off closures to `exp_service.holiday_overrides`. A null override removes a moved date; a label adds the actual closure. Run `php artisan config:clear` after a configuration change and restart long-running application processes.

Sources used for the correction:

- [Republic Day observance, 3 July 2026 — Ministry of the Interior](https://mint.gov.gh/media/press-releases/declaration-of-friday-3rd-july-2026-as-a-public-holiday-2)
- [Founder’s Day, 21 September 2026 — Ministry of the Interior](https://mint.gov.gh/media/press-releases/declaration-of-monday-21st-september-2026-as-a-statutory-public-holiday)
- [Constitution Day observance, 9 January 2026](https://www.myjoyonline.com/january-9-declared-public-holiday/)
- [Eid/Shaqq closures, 20 and 23 March 2026](https://www.graphic.com.gh/news/general-news/march-20-and-23-declared-public-holidays-for-eid-ul-fitr-and-shaqq-day.html)

The connected local database had **zero dentist roster entries** at review. No individual availability was invented. To populate it, open **Dentists → roster**, choose the start date, 1–12 weeks and the confirmed active dentist(s), review the preview, then generate standard shifts. Existing overlapping windows are preserved; review and edit them separately if imported later. Manual roster entry is still an override and can create windows outside standard hours; use the standard generator for service cover. This calendar does not change VitalPBX time conditions: configure the same hours and closures on the PBX separately.

## Why the screenshots show incomplete journeys

VitalPBX has its own stored IVR report. EXP reconstructs calls from the live AMI frames it receives. The screenshots show both missing menu evidence and a `connection_lost` interval overlapping a call. A current LIVE heartbeat proves current connectivity, not that every earlier event was received. Missing Hangup evidence can leave old interactions active. PBX “Answered” does not prove that an agent or human dentist answered.

Existing fixes capture DTMFEnd/Newexten/UserEvent selections in configured IVR contexts, filter noise and bound projection/replay. This change also preserves later repeated selections of the same option, while collapsing observations within two seconds. The new importer recovers stored PBX selections missed by AMI. It does not record audio, reconstruct missing answers, invent a hangup or certify a dentist connection.

## Apply journey rollout

1. Build frontend assets with `npm run build`; publish guide permissions with the narrow seeder above. Refresh the browser to load **User guides → Agent guide / Viewer guide / Super Administrator guide**, filtered by permission.
2. Clear stale configuration with `php artisan config:clear`. Confirm `services.exp_pbx.ivr_contexts` includes actual PBX contexts (defaults include `IVR-*`, `app-ivr`, `app-announcement`). Avoid displaying configuration output containing credentials in shared logs.
3. Restart the existing supervised `exp:ami-listen` process using its actual process manager. Do not start another listener. `exp:ami-listener` is not a valid command. Confirm heartbeat, event freshness and absence of new outages during a test call.
4. Replay retained AMI rows outside service hours:

   ```sh
   php artisan exp:correlate-calls --connection=NAME --since="2026-11-20 00:00:00" --limit=500
   ```

   Continue with the printed `--after` value, retaining `--connection` and `--since`, until zero rows are processed. Rebuild existing projections similarly with `--rebuild --limit=500`, paging by the printed interaction ID. Raw event IDs and rebuild interaction IDs are different cursors.

5. For missed selections, export PBX IVR evidence read-only. Verify the installed VitalPBX schema first; no table/column names or CSV-to-ID join can be assumed from screenshots. Resolve the exact caller-leg `uniqueid` from PBX call evidence. Do not match on phone number and approximate time. Write one JSON object per line:

   ```json
   {"uniqueid":"1795169281.1443","menu":"IVR-2","option":"3","occurred_at":"2026-11-20 10:08:10"}
   ```

   `occurred_at` must be the selection timestamp, not a report's call-arrival timestamp unless that is what the source actually records. Options may contain 1–32 digits, `*` or `#`; an exported sequence is retained as a sequence rather than interpreted as separate confirmed presses. Exclude caller names/numbers from exports.

   ```sh
   php artisan exp:import-ivr-records /secure/path/ivr.jsonl --connection=NAME --timezone=Africa/Accra
   ```

   The importer requires an existing exact caller-leg match on the selected PBX connection, validates source time against the observed call interval, stores only allowlisted evidence in UTC, rolls back the file on an invalid row, deduplicates replay and rebuilds changed timelines. Importing the same file twice is safe. It preserves outage warnings and does not close stale calls. If no caller leg exists, restore/replay retained AMI evidence first; creating a whole journey from PBX-only CDR/CEL evidence remains additional work.

6. Import CDR/CEL exports through `exp:import-call-records cdr|cel FILE --timezone=Africa/Accra --connection=NAME`, then use the existing reconciliation workflow. These imports compare evidence; they are not a complete replacement for AMI and do not by themselves repair every missing live event. Review stale active interactions instead of assigning a guessed end time.
7. Configure a recurring read-only PBX export job, secure transport and supervised importer with retries/alerts if automatic historical recovery is required. This delivery pipeline needs the actual PBX export schema and access; it has not been configured here. Restrict and expire temporary exports using the agreed retention policy.

## Live acceptance matrix

For each scenario retain the PBX IDs and compare EXP detail to PBX evidence: option 1, callback option 2, option 3 with no answer, agent answer, failed dentist attempt followed by success, private briefing/transfer, caller hangup, dentist hangup and listener disconnect/reconnect. Confirm later repeated menu selections remain visible. Deliberately test an outage and import recovery. Keep unobserved timing null and retain outage warnings.

Verify read-only users cannot save answers, invoke call controls, change directories/settings or export without an explicit grant. Verify Viewer scope against the actual service-owner requirement before rollout. Confirm scheduler execution and supervised listener recovery on the deployment host, rather than assuming registration in `routes/console.php` proves the scheduler runs.

For a full audio recording, this work captures journey events only. Audio needs a separate PBX recording/retention/access integration; there is no audio recording or playback implementation in EXP.
