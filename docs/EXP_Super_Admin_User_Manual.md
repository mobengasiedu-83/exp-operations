# EXP Operations — Super Administrator Role Guide

**Audience:** Platform owners and system administrators (role: **Super Administrator**)
**Applies to:** EXP Operations web console (Laravel + Inertia/Vue) and its PBX integration
**Service window:** Monday to Friday, 10:00–14:00 Ghana time (Africa/Accra), closed on Ghana public holidays
**Document version:** 1.0

---

## Contents

1. [About this manual](#1-about-this-manual)
2. [The Super Administrator role](#2-the-super-administrator-role)
3. [Signing in and personal security](#3-signing-in-and-personal-security)
4. [Finding your way around](#4-finding-your-way-around)
5. [The Administration hub](#5-the-administration-hub)
6. [Users: creating and maintaining accounts](#6-users-creating-and-maintaining-accounts)
7. [Roles and permissions](#7-roles-and-permissions)
8. [The permission catalogue](#8-the-permission-catalogue)
9. [Active sessions and lockouts](#9-active-sessions-and-lockouts)
10. [Service settings and service hours](#10-service-settings-and-service-hours)
11. [Questionnaires and screening protocols](#11-questionnaires-and-screening-protocols)
12. [Dentist directory and rostering](#12-dentist-directory-and-rostering)
13. [PBX connections](#13-pbx-connections)
14. [AMI health and integration evidence](#14-ami-health-and-integration-evidence)
15. [Structured logs, CDR and CEL](#15-structured-logs-cdr-and-cel)
16. [The audit log](#16-the-audit-log)
17. [Reports and executive reporting](#17-reports-and-executive-reporting)
18. [Knowledge bank governance](#18-knowledge-bank-governance)
19. [Quality assurance, training and incidents](#19-quality-assurance-training-and-incidents)
20. [Callbacks, referrals and case oversight](#20-callbacks-referrals-and-case-oversight)
21. [Publishing the role manuals](#21-publishing-the-role-manuals)
22. [Security, privacy and retention](#22-security-privacy-and-retention)
23. [Backup, recovery and release routine](#23-backup-recovery-and-release-routine)
24. [Troubleshooting](#24-troubleshooting)
25. [Daily, weekly and monthly checklist](#25-daily-weekly-and-monthly-checklist)
26. [Glossary](#26-glossary)
27. [Quick reference card](#27-quick-reference-card)

---

## 1. About this manual

This is the complete operating guide for the **Super Administrator** of the EXP
Operations console. It covers the duties that belong to the platform owner:

- provisioning and retiring staff accounts;
- deciding what each role may see and change;
- keeping the service window, questionnaires and dentist directory correct;
- keeping the PBX integration healthy and its evidence honest;
- reviewing the audit trail, reports and clinical governance queues;
- protecting the install: secrets, backups, releases and access reviews.

It is the **third** of three role manuals. The other two are written for the
people who use the console all day:

| Manual | Written for | What it covers |
|---|---|---|
| **Agent role guide** (`/guides/agent`) | Agents and supervisors | Answering calls, consent, the safety screen, SBAR handover, transfers, dispositions |
| **Viewer role guide** (`/guides/viewer`) | Read-only viewers | Reading dashboards, call history, call records, dentist coverage and published reports |
| **Super Administrator role guide** (this manual) | Platform owners | The whole administration, integration and governance surface |

Read the other two manuals as well. You will be asked to support the people who
use them, and you cannot judge whether a screen is behaving correctly unless you
know what it is supposed to say.

Three principles run through this manual:

1. **The console records evidence; it never guesses.** If the PBX did not report
   an event, the screen says so. Do not "fix" a figure by editing the database.
2. **Least privilege is the default.** A new account starts with the narrowest
   role that can do the job. Access is widened only on a written request, and it
   is reviewed.
3. **Every privileged action is attributable.** Users, roles, settings and
   service hours are audited. Act as though your name is on the record, because
   it is.

> The menus in this guide are described by their **titles**, not by their
> internal keys, so the words match the screen.

---

## 2. The Super Administrator role

The **Super Administrator** role holds every permission defined in the
application, plus the three guide permissions:

| Permission | What it lets you do |
|---|---|
| `view agent guides` | Open the Agent role guide |
| `view viewer guides` | Open the Viewer role guide |
| `view super admin guides` | Open this manual |
| `view/create/edit/delete users` | Provision and retire staff accounts |
| `view/create/edit/delete roles` | Shape what a role may do |
| `view permissions` | Read the permission catalogue |
| `view settings`, `edit settings` | Service settings, service hours and PBX connection records |
| `view sessions`, `terminate sessions` | Review and end signed-in sessions |
| `view audit` | Read the audit log |
| `view pbx` | Live operations, PBX connections, AMI health, integration logs, connection health, structured logs, CDR/CEL |
| `view operations overview` | Read the Operations overview on the Dashboard — KPIs, AMI health and the masked active-call list. Held by **every** role; it does not open the live console |
| `view call history`, `export call history` | Read and export reconciled call history |
| `control calls` | Use browser call controls on a case |
| `view reports`, `view executive reports`, `export reports` | Run, read and export every report |
| `view cases`, `create cases`, `edit cases`, `view all cases`, `review cases` | Read any case, open a new one, document it, and run quality review |
| `manage screening protocols` | Version and activate the questionnaire |
| `view dentists`, `edit dentists` | Maintain the dentist directory, numbers, credentials and rosters |
| `view/edit callbacks`, `view/edit referrals` | Oversee follow-up work |
| `view knowledge bank`, `manage knowledge bank`, `approve clinical knowledge`, `approve brand knowledge` | Publish and approve guidance |
| `view/manage/approve quality assurance` | Run the QA programme |
| `view/manage training`, `authorise live calls` | Train staff and authorise live certification |
| `view/report/manage/close incidents` | Investigate and close incidents |

### 2.1 What "full access to all three guides" means

Only the Super Administrator role holds all three guide permissions. The
seeded roles resolve like this:

| Role | Agent guide | Viewer guide | Super Administrator guide |
|---|---|---|---|
| Super Administrator | Yes | Yes | Yes |
| Administrator | Yes | Yes | — |
| Supervisor | Yes | — | — |
| Agent | Yes | — | — |
| Viewer | — | Yes | — |
| All other roles | — | — | — |

This is enforced in three places, not one: the shared navigation prop that
builds the menus, the Guides index, and the `guides/{guide}` controller. A guide
you cannot see is a guide you cannot open by typing its URL.

> **Administrator and Super Administrator hold the same operational
> permissions in this build.** The guide permission is the deliberate
> difference: the governance manual is written for the platform owner and is not
> delegated. If your practice wants a second person to read it, give that person
> the Super Administrator role — do not hand out the guide permission on its
> own.

### 2.2 What the role does *not* do

- It does not change clinical evidence. Case notes, safety screens, transfers
  and call records are append-only history; corrections are recorded as new
  entries, not overwritten rows.
- It does not listen to a recording unless the install has a recording feature
  and the caller consented.
- It does not bypass the service window. The workspace behaves the same for you
  as for an agent.

---

## 3. Signing in and personal security

### 3.1 Sign-in

1. Open the console URL for your environment (for example
   `https://exp-operations.example.com`).
2. Enter your work email address and password.
3. If your account has **two-factor authentication** enabled, enter the current
   code from your authenticator app, or approve the passkey prompt.
4. If your email address has not been verified, the console asks you to verify
   it before any other screen opens.

The console locks out repeated failed attempts. If you are locked out, ask a
second Super Administrator to reset your password from **Administration → Users
& roles**; do not create a second account for yourself.

### 3.2 Two-factor authentication and passkeys

Open **Settings → Security** (your own account menu) to:

- enable or re-enable two-factor authentication;
- view and regenerate recovery codes;
- register a passkey;
- change your password.

**Enable two-factor authentication on every administrator account.** A Super
Administrator account protected by a password alone is the single largest risk
to this install. Store recovery codes offline, not in the same password manager
as the password.

### 3.3 Password rules

- Minimum eight characters, checked against Laravel's default password rules
  (uncompromised passwords only).
- Never share an administrator password, including with a colleague covering a
  shift. Create them their own account with the narrowest role that lets them
  help.
- A shared account destroys the audit trail. The audit log records *who* acted;
  a shared login makes that record meaningless.

### 3.4 Your own account hygiene

- Use a named account, never a generic `admin@` mailbox.
- Keep at least **two** Super Administrator accounts so one person's holiday does
  not lock the practice out of administration.
- Review your own sessions monthly under **Administration → Active sessions** and
  terminate anything you do not recognise.

---


## 4. Finding your way around

The shell is the same for every role; only the menus a permission allows are
rendered.

| Menu | Route | What it is for |
|---|---|---|
| **Dashboard** | `/dashboard` | Post-login landing page carrying the Operations overview. Every role reads the overview; the live console link needs `view pbx` |
| **Agent Workspace** | `/workspace` | Cases assigned to you; the agent's working screen |
| **Calls** | `/live-operations/calls` | Agent calls, DID journeys, all calls and the reconciled history |
| **Reports** | `/reports` | The operational report catalogue |
| **Dentists** | `/dentists` | Directory, numbers, credentials, rosters, availability, performance |
| **Guides** | `/guides` | The role manuals, including this one |
| **Administration** | `/administration` | The configuration hub described in section 5 |

The header also carries one icon per manual you may open, and the sidebar
footer carries a **Manuals** group with the same links. Both are built from the
same permission-filtered navigation data as the **Guides** menu, so all three
can never disagree.

### 4.1 Where the Super Administrator usually starts the day

1. **Dashboard** — the service window state and yesterday's reconciliation
   flags.
2. **Administration → AMI health** — is the listener connected, and is the
   heartbeat fresh?
3. **Administration → Connection health** — PBX, Redis and queue dependencies.
4. **Administration → Audit log** — anything unusual overnight.
5. **Reports** — only when a figure needs explaining.

---

## 5. The Administration hub

**Route:** `/administration`

The hub is a grid of cards. Each card appears only when you hold the permission
behind it, and each is a shortcut to a screen described later in this manual.

| Card | Opens | Permission |
|---|---|---|
| Users & roles | `/admin/users` | `view users` |
| Roles & permissions | `/admin/roles` | `view roles` |
| Permission catalogue | `/admin/permissions` | `view permissions` |
| Active sessions | `/admin/sessions` | `view sessions` |
| Questions & versions | `/screening-protocols` | `manage screening protocols` |
| Dentist directory | `/dentists` | `view dentists` |
| Dentist numbers | `/dentists/numbers` | `view dentists` |
| Dentist credentials | `/dentists/credentials` | `view dentists` |
| Manual availability | `/dentists/availability` | `view dentists` |
| Dentist rosters | `/dentists/rosters` | `view dentists` |
| Dentist performance | `/dentists/performance` | `view dentists` |
| Service settings | `/admin/settings` | `view settings` |
| Service hours | `/admin/service-hours` | `view settings` |
| PBX connections | `/admin/pbx-connections` | `view pbx` |
| AMI health | `/admin/ami-health` | `view pbx` |
| Integration logs | `/admin/integration-logs` | `view pbx` |
| Connection health | `/admin/connection-health` | `view pbx` |
| Structured logs | `/admin/structured-logs` | `view pbx` |
| CDR / CEL records | `/admin/cdr-cel` | `view pbx` |
| Audit log | `/admin/audit` | `view audit` |

Below the cards, **Roles in use** lists every role with the number of users
holding it. A role showing `0 users` is a candidate for deletion; a role showing
a large number after a restructure usually means somebody was given the wrong
role.

> Some queues — Knowledge Bank, Quality Assurance, Training, Incidents,
> Callbacks and Referrals — are **not** on the Administration hub. They are
> operational menus and are reached from the main navigation. They are covered
> in sections 18 to 20.

---


## 6. Users: creating and maintaining accounts

**Route:** `/admin/users` (permissions: `view users`, `create users`,
`edit users`, `delete users`)

The Users screen lists every account with its roles, 25 per page, and a form for
adding or editing one.

### 6.1 Creating an account

1. Open **Administration → Users & roles**.
2. Fill in the **Add Users** form:
   - **Name** — the person's real name, as it should appear in the audit log.
   - **Email** — a mailbox the person controls. This is also the password-reset
     address.
   - **Password** and **Confirm password** — minimum eight characters.
   - **Active** — leave on for a new starter.
   - **Roles** — tick only what the job needs.
3. Save. The account can sign in immediately.

Name the account after the person, not the job. When the person leaves, the
audit trail should still read clearly.

### 6.2 Choosing the role

| Person | Role | Why |
|---|---|---|
| Call-centre agent | **Agent** | Cases, call controls, call history, dentist directory |
| Shift lead | **Supervisor** | Agent permissions plus all cases, review and reports |
| Read-only stakeholder | **Viewer** | Call history, reports and the directory, no edits |
| Operations administrator | **Administrator** | Full operational permissions |
| Platform owner | **Super Administrator** | Everything, plus this manual |
| Clinical lead | **Lead Dentist / Clinical Supervisor** | Cases, protocols, approvals, QA |
| Dentist | **Dentist** | Own cases and the directory |
| Dental assistant | **Dental Assistant** | Cases, callbacks, referrals, directory |
| QA reviewer | **QA Reviewer** | QA, training, audit and reports |
| Executive | **Executive / Management** | Read-only reporting and governance |
| Platform/IT manager | **Platform Manager** | Settings, PBX and integration health |

A user may hold more than one role; the permissions are combined. Prefer one
clear role over three overlapping ones.

### 6.3 Editing an account

- Changing **Roles** replaces the set entirely; it does not add to it. Re-tick
  every role the person should keep.
- Leaving **Password** blank keeps the current password.
- Turning **Active** off blocks sign-in but keeps the account and its history.

### 6.4 The two safety rails

Both are server-side, not cosmetic:

1. **You cannot grant what you do not hold.** If you assign a role containing a
   permission your own account lacks, the save is rejected with `403`. This is
   why an Administrator created by a limited role cannot escalate themselves.
2. **You cannot edit an account that holds permissions you lack.** The console
   authorises every permission on the target account first.

If a legitimate change hits either rail, ask a Super Administrator who holds the
permission to make it. Do not create a workaround role.

### 6.5 Retiring an account

Use **Deactivate**, not deletion. The console has no hard-delete for users: the
account, its roles and its audit history remain, and the person simply cannot
sign in. Also:

1. Terminate their sessions under **Administration → Active sessions**.
2. Remove them from any shift handover notes or rota documents.
3. Ask them to hand over any recovery codes.

---


## 7. Roles and permissions

**Route:** `/admin/roles` (permissions: `view roles`, `create roles`,
`edit roles`, `delete roles`)

A **role** is a named bundle of permissions. A **permission** is a single
server-side check such as `edit cases`. Screens and buttons are conveniences;
the permission is the control.

### 7.1 The seeded roles

| Role | Intended for | Notable limits |
|---|---|---|
| **Super Administrator** | Platform owner | None; the only role with all three manuals |
| **Administrator** | Operations administration | Every operational permission; no Super Administrator guide |
| **Platform Manager** | IT/platform | Settings, PBX, users read, incidents |
| **Lead Dentist / Clinical Supervisor** | Clinical governance | Cases, protocols, approvals, QA, reports |
| **Dental Assistant** | Chairside support | Cases, callbacks, referrals, directory |
| **Dentist** | Practising dentists | Own cases and the directory |
| **QA Reviewer** | Quality programme | QA, training, audit, reports |
| **Executive / Management** | Leadership | Read-only reporting and oversight |
| **Agent** | Call centre | Cases, call controls, call history, directory |
| **Supervisor** | Shift lead | Agent plus all cases, review and reports |
| **Viewer** | Read-only stakeholder | Call history, reports, directory; no edits |

### 7.2 Creating or editing a role

1. Open **Administration → Roles & permissions**.
2. Enter a **Name** and tick the **Permissions**.
3. Save.

The same two safety rails as users apply: you can only add a permission you hold
yourself, and the server rejects anything else.

Guidance:

- Name the role for the **job**, not the person (`Duty Supervisor`, not
  `Ama's role`).
- Keep the permission set as small as the job allows. If a person needs one
  extra screen occasionally, consider a second role rather than widening a
  shared one.
- Write down why a role exists. A role nobody can explain is a role nobody will
  dare remove.

### 7.3 Deleting a role

Deletion is allowed with `delete roles`, but do it carefully:

1. Check **Roles in use** on the Administration hub — a role with users should
   not be deleted.
2. Move affected users to the correct role first.
3. Delete only genuinely unused roles.

A deleted role leaves its former holders with no role permissions. They can
still sign in, but their menus shrink to the Dashboard.

### 7.4 Access reviews

Run a review every quarter:

1. **Administration → Users & roles**: every active account should map to a
   current person and a current role.
2. **Administration → Roles & permissions**: does each role still match its
   written purpose?
3. **Administration → Permission catalogue**: confirm no unexpected permission
   appeared after a release.
4. Record who reviewed what and when, outside the console.

---

## 8. The permission catalogue

**Route:** `/admin/permissions` (permission: `view permissions`)

This screen is read-only. It lists every permission the server can check, in
alphabetical order, 25 per page. It exists so you can answer two questions
without opening code:

- *What exactly does this role allow?* — read the role's permission names here.
- *Why did this button vanish?* — check whether its permission still exists and
  whether the role still holds it.

Permissions are seeded by the application, not typed by hand. If a release adds
a permission, it appears after `php artisan db:seed` (or the targeted seeder)
has run. If a permission is missing after a release, the seeder did not run.

---

## 9. Active sessions and lockouts

**Route:** `/admin/sessions` (permissions: `view sessions`,
`terminate sessions`)

The screen lists signed-in sessions newest first: user, email, IP address, user
agent, last activity, and a **Terminate** action.

### 9.1 When to terminate a session

- A person has left or been suspended.
- A device was lost or stolen.
- You suspect credential sharing.
- A support session was opened on a shared machine and not closed.

Termination deletes the session row, so the next request from that browser is
signed out.

### 9.2 Lockouts

A lockout is usually one of these:

| Symptom | Likely cause | Fix |
|---|---|---|
| Password rejected repeatedly | Wrong account, or password changed by an administrator | Reset the password from **Users & roles** |
| Sign-in accepted, then bounced to verification | Email address not verified | Verify the address, or re-send the verification mail |
| Two-factor code rejected | Device clock drift | Correct the device time, then use a recovery code |
| Signed in, but every screen says 403 | Account has no role | Assign the correct role |

Do not disable two-factor authentication to solve a clock problem.

---


## 10. Service settings and service hours

### 10.1 Service settings

**Route:** `/admin/settings` (permissions: `view settings`, `edit settings`)

Settings are key/value rows grouped by area. Each row shows its **key**, group,
**value**, **cast**, **description** and whether it is a feature flag. Editing is
inline.

| Field | Meaning |
|---|---|
| **value** | The stored value, as text; the cast decides how it is read |
| **cast** | `string`, `boolean`, `integer`, `float` or `array` |
| **is_feature_flag** | Marks a switch that turns a feature on or off |
| **description** | Plain-English explanation for the next administrator |

Rules:

- Change one setting at a time, and note why.
- Never change a **cast** unless the value is rewritten to match. A boolean
  stored as `yes` is read as false.
- A feature flag is a release control, not a preference. Turning one off is a
  conscious rollback and should be recorded.

### 10.2 Service hours

**Route:** `/admin/service-hours` (permissions: `view settings`, `edit settings`)

The workspace uses this schedule to decide whether the line is open. The screen
shows the current **service state**, the configured windows and the next six
**public holidays**.

| Field | Notes |
|---|---|
| **Name** | e.g. `Standard Hours`, `Ramadan Hours` |
| **Opens at / Closes at** | 24-hour clock; closing must be after opening |
| **Days** | 1 = Monday … 7 = Sunday; at least one day |
| **Is closed** | A closed-day row; overrides the times |
| **Effective from / until** | Optional date window; `until` on or after `from` |
| **Description** | Why the window exists (holiday, Ramadan, emergency cover) |

The seeded default is **Monday to Friday, 10:00–14:00**, closed on Ghana public
holidays. Changing it changes what every agent sees immediately, so:

1. Announce the change to the shift lead first.
2. Create a **new** row with an effective date rather than editing history where
   possible.
3. Verify the state panel reads **open** or **closed** as intended before you
   leave the screen.

> Times are stored in the application's timezone (Africa/Accra). Do not enter
> UTC.

---

## 11. Questionnaires and screening protocols

**Route:** `/screening-protocols` (permission: `manage screening protocols`)

The questionnaire is versioned. An agent's workspace always uses the **active**
version, and a version that is already in use is never rewritten in place.

### 11.1 The lifecycle

| State | Meaning |
|---|---|
| Draft | Editable, not offered to agents |
| Active | The version agents see; exactly one at a time |
| Retired | Closed to new use; kept for evidence |

### 11.2 Publishing a change

1. Open **Administration → Questions & versions**.
2. Create the new protocol version and edit its questions:
   - question text and type;
   - whether an answer is required;
   - the conditional rule that shows or hides a follow-up;
   - the red-flag behaviour for safety questions.
3. Save, then re-read the whole questionnaire as if you were an agent.
4. **Activate** the version. The previous active version is retired
   automatically; cases already in progress keep the version they started with.
5. Tell the shift lead which questions changed.

### 11.3 What not to do

- Do not retire the active version before activating its replacement; the
  workspace would have nothing to show.
- Do not change a question during a live shift unless it is a clinical safety
  correction agreed with the clinical lead.
- Do not delete a retired version. It is the evidence of what a caller was
  asked.

---

## 12. Dentist directory and rostering

**Routes:** `/dentists`, `/dentists/numbers`, `/dentists/credentials`,
`/dentists/availability`, `/dentists/rosters`, `/dentists/performance`
(permissions: `view dentists`, `edit dentists`)

The directory decides who an agent may transfer a caller to. If it is wrong, the
caller is failed at the most important moment of the journey.

### 12.1 Dentist records

Each dentist carries a name, practice details, contact numbers, credentials and
rosters. Editing requires `edit dentists`; reading requires `view dentists`.

### 12.2 Numbers

- Numbers are stored in **E.164** format (`+233…`).
- Each number has an approval state. Only **approved** numbers can be selected
  for a warm transfer.
- One number is the **primary destination**; the others stay as fallbacks.
- Block a number immediately if it is reported wrong, and record why.

### 12.3 Credentials

Track registration and credential expiry. The transfer screen warns when a
credential is expired or about to expire. Keep the expiry dates current: an
expired credential must not be presented as a valid destination.

### 12.4 Availability and rosters

- **Rosters** publish on-call shifts and recorded leave windows. **Generate**
  builds a roster from the configured pattern; review it before publishing.
- **Manual availability** records available, unavailable and busy states by
  hand. Use it for same-day changes the roster cannot know about.
- A dentist who is unavailable is not offered for transfer, even if they are on
  the roster.

### 12.5 Performance

The performance screen reports observed transfer evidence — ring, answer,
handover and consultation — and states plainly where evidence is missing. It is
not a disciplinary tool on its own: read it with the call record.

### 12.6 Monthly directory review

1. Every dentist still practising is listed and active.
2. Every approved number connects.
3. Every credential expiry is in the future.
4. Next month's rosters are published before the last working day.
5. Dentists with no transfers for a quarter are confirmed as intentional.

---


## 13. PBX connections

**Route:** `/admin/pbx-connections` (permission: `view pbx`; writing also needs
`edit settings`)

A connection row describes how the console reaches the PBX.

| Field | Rules |
|---|---|
| **Name** | Required and unique; the listener matches on this name |
| **Host** | Required; hostname or IP of the PBX/AMI endpoint |
| **AMI port** | 1–65535; defaults to the standard AMI port when omitted |

### 13.1 Adding or changing a connection

1. Confirm the host and port with the telephony engineer.
2. Create the row, or update the existing one.
3. Restart the AMI listener so it picks up the change.
4. Run **AMI health → Run health check** and confirm a fresh heartbeat.

### 13.2 Deleting a connection

Deletion is refused when the connection has **call history** or **telemetry
gaps**. The message tells you to update the configuration instead. That guard
exists because deleting the row would orphan the evidence attached to it. Rename
or re-point it; do not try to force a delete.

### 13.3 Secrets

Credentials for the PBX belong in the environment file, not in this screen and
never in a manual. Rotate them through your secrets process, then restart the
listener and the queue workers.

---

## 14. AMI health and integration evidence

### 14.1 AMI health

**Route:** `/admin/ami-health` (permission: `view pbx`)

The screen shows the configured connection, the `ami_listener` health record and
the import components (`import_cdr`, `import_cel`, `call_correlation`).

**Run health check** evaluates the connection and records a result:

- `healthy` when the connection status is `connected` **and** the last heartbeat
  is newer than the stale threshold (`services.exp_pbx.heartbeat_stale_seconds`,
  default **90 seconds**);
- `unhealthy` otherwise, with the reason *"AMI listener is not connected or
  heartbeat is stale."*

The result is written to integration health and a warning is logged when the
check fails. A health check is a **probe**, not a repair: if it reports
unhealthy, fix the listener.

### 14.2 Connection health

**Route:** `/admin/connection-health` (permission: `view pbx`)

Read-only rows for the PBX, Redis and queue dependencies, each with its last
update and how long ago that was. The stale threshold is shown on the screen.

| Dependency | If it is stale |
|---|---|
| PBX/AMI | Live call state and events stop updating |
| Redis | Queues, cache and broadcasts degrade |
| Queue worker | Reconciliation, imports and notifications fall behind |

### 14.3 Integration logs

**Route:** `/admin/integration-logs` (permission: `view pbx`)

Listener and reconciliation events, newest first, 50 per page. Filter by
**event**, **from** and **to**. Entries are sanitised: they describe what the
integration did, not what the caller said.

Use integration logs when the question is *"did the system receive the event?"*.
Use the audit log when the question is *"who changed something?"*.

### 14.4 The daily integration check

1. **AMI health** — run the check; heartbeat fresh.
2. **Connection health** — PBX, Redis and queue all current.
3. **Integration logs** — no unexplained error burst overnight.
4. **CDR/CEL** — yesterday's records imported.
5. **All calls** — reconciliation flags reviewed by the shift lead.

---

## 15. Structured logs, CDR and CEL

### 15.1 Structured logs

**Route:** `/admin/structured-logs` (permission: `view pbx`)

Recent application log entries, 50 per page, filterable by **level** (`info`,
`warning`, `error`) and **connection**. They deliberately exclude caller
content, so they are safe to read in an open office and safe to share with a
developer.

### 15.2 CDR and CEL records

**Route:** `/admin/cdr-cel` (permission: `view pbx`)

The raw reconciliation evidence exported from the PBX:

- **CDR** — one row per call leg, with start, answer, end and disposition.
- **CEL** — channel events, including transfers and bridges.

Filters: **type** (`cdr` or `cel`), **uniqueid**, **linkedid**, **from**, **to**;
50 rows per page. Use `linkedid` to follow one caller across every leg of a
transfer.

### 15.3 When to use which screen

| Question | Screen |
|---|---|
| Did the PBX record the call at all? | CDR / CEL records |
| Did the console receive the event? | Integration logs |
| How does the reconciled journey read? | Calls → All calls |
| Why did the app log an error? | Structured logs |
| Who changed a setting? | Audit log |

Never reconcile by editing a CDR or CEL row. Re-run the import, or record a
correlation exception with a reason.

---


## 16. The audit log

**Route:** `/admin/audit` (permission: `view audit`)

Every administration change, approval decision and restricted evidence access is
recorded with the acting user, the subject, the time and the change. Entries are
newest first, 25 per page.

### 16.1 What to look for

- **Users and roles changed outside a planned change window.**
- **Permissions added to a role** that no request explains.
- **Service hours edited** on a day the line unexpectedly closed early.
- **Approvals** (knowledge versions, translations, QA, incidents, certifications)
  signed by someone who should not have that authority.
- **Evidence access** records: who opened a restricted incident record, and when.

### 16.2 Reading an entry

| Part | Meaning |
|---|---|
| **Causer** | The signed-in user responsible; blank means a scheduled or system action |
| **Subject** | The record that changed |
| **Description** | The action taken |
| **Properties** | Old and new values where the action records them |
| **Time** | When it happened, in the application timezone |

### 16.3 Investigations

1. Start from the time the problem was reported and work backwards.
2. Pair the audit entry with the relevant operational screen (a role change with
   the user's menu; a settings change with the service state).
3. Export or transcribe the entries you rely on; the log is paginated and
   retention is finite.
4. Do not edit the log. If something must be corrected, correct the underlying
   record so both the mistake and the correction are visible.

### 16.4 Retention

The audit log is the practice's defence in a complaint or inspection. Agree a
retention period with the clinical lead (typically the same as the case record
retention) and make sure backups cover it.

---

## 17. Reports and executive reporting

**Route:** `/reports` (permissions: `view reports`, `view executive reports`,
`export reports`)

Reports are built from evidence the console already stores. Every figure carries
its definition, numerator, denominator and exclusions; a KPI that cannot be
measured is shown empty rather than estimated.

### 17.1 The catalogue

| Category | Reports |
|---|---|
| Operations | Daily operations, Weekly operations, Monthly management |
| Transfers | Warm transfer quality |
| Callback follow-up | Callback follow-up |
| Quality assurance | Quality assurance |
| Dentist engagement | Dentist engagement and cost |
| Safety and incidents | Safety and incidents, Short and nuisance call dispositions |
| Executive | Executive summary (*Sensitive*) |

A Viewer sees these definitions with `view reports`. The **Executive summary**
additionally requires `view executive reports`, and CSV export requires
`export reports`. This is deliberate: read-only monitoring must never be able to
export.

### 17.2 Running a report

1. Choose a definition from the catalogue.
2. Set **From** and **To**. The page opens on today (Ghana calendar date); the
   maximum window is 92 days unless the installation is configured otherwise,
   and the helper text states both the timezone and the limit.
3. **Save run** stores an immutable snapshot with a reference such as
   `RPT-20261119-20261119-AB12CD`, the period, generator, KPI count, definition
   revision and a checksum. Re-running the same period creates a new revision
   instead of overwriting the old one.
4. Read the definition **before** quoting the number. Two reports can show
   different totals for the same hour because they use different grains.

### 17.3 Definitions and revisions

Report definitions are **code-owned** (`Reporting::definitions()`), published by
`ReportCatalogueSeeder`. Seeding uses `updateOrCreate`, so:

- re-seeding refreshes the wording and numerator/denominator;
- re-seeding never re-enables a definition an operator disabled;
- a wording change is visible as a revision in the report history.

If a definition is disabled and you want it back, enable it deliberately and
record why.

### 17.4 Explaining a number

Always answer in this order:

1. **Definition** — what exactly is counted?
2. **Grain** — per call, per case, per day or per dentist?
3. **Exclusions** — which calls are left out, and why?
4. **Missing evidence** — which part of the figure is unknown?
5. **Source** — which screen holds the underlying records?

If step 4 has an answer other than "none", say so before discussing the trend.

### 17.5 Month-end routine

1. Run the monthly management report for the closed month.
2. Compare it with the weekly reports already reviewed.
3. Check that the reconciliation exceptions for the month are closed or
   explained.
4. Archive the exported figures with the date, the definition revision and who
   ran them.

---


## 18. Knowledge bank governance

**Routes:** `/knowledge-bank`, `/knowledge-bank/offline` (permissions:
`view knowledge bank`, `manage knowledge bank`, `approve clinical knowledge`,
`approve brand knowledge`)

The knowledge bank is what agents read while a caller is on the line. It is
versioned, approved and published; nothing is edited silently.

### 18.1 Articles and versions

| Concept | Meaning |
|---|---|
| **Article** | The stable subject, with a category |
| **Version** | The content: draft → in review → approved → published → retired |
| **Translation** | A localised version of a published article, separately approved |

Categories: `faq`, `script`, `red_flag`, `referral_guidance`, `emergency`,
`outage`. The **offline** view publishes only `red_flag`, `emergency` and
`outage`, so the guidance an agent most needs survives a connectivity failure.

### 18.2 The approval path

1. An author creates an article and its first version.
2. The version is submitted for review.
3. A reviewer with the right authority records a decision:
   - `approve clinical knowledge` for clinical content;
   - `approve brand knowledge` for scripts and brand wording.
4. The approved version is **published**; the previous version is retired.
5. Retiring an article removes it from the agent's view but keeps its history.

### 18.3 Your duties

- Ensure every published clinical claim has a named clinical approver.
- Never publish an emergency script that has not been clinically approved —
  agents will read it at the worst possible moment.
- Keep the offline set small, accurate and current; a stale emergency script is
  worse than none.
- Review translations before approving them; a translation is a new clinical
  statement, not a formatting exercise.

---

## 19. Quality assurance, training and incidents

### 19.1 Quality assurance

**Routes:** `/quality-assurance` and the review screens (permissions:
`view quality assurance`, `manage quality assurance`,
`approve quality assurance`, `review cases`)

A QA review scores a sampled case against the protocol. Statuses are `draft` and
`finalised`; outcomes are `pass`, `remediate` or `critical_fail`. Calibration
sessions keep reviewers aligned on the same rubric.

As Super Administrator you:

- confirm reviewers hold `manage quality assurance`;
- confirm only the intended people hold `approve quality assurance`;
- check that `critical_fail` reviews produce a coaching action, not just a score;
- review calibration records after any rubric change.

Scores are evidence about a conversation, not a verdict about a person. Read the
case before discussing the score.

### 19.2 Training and certification

**Routes:** `/training` and the certification screens (permissions:
`view training`, `manage training`, `authorise live calls`)

Training modules move `draft` → `published` → `retired`. Passing a module
records a certification. Opening a new case is authorised by the `create cases`
permission, which the Agent role holds; a current live certification also
authorises it for operators who were granted one before the permission existed.
Without either gate the agent can still document existing cases, but the
*Open case* action is rejected.

Your duties:

- keep the module library current with the questionnaire and the knowledge bank;
- expire certifications deliberately, never by accident;
- authorise live calls only after the assessment is complete and recorded;
- investigate any account with `authorise live calls` that you did not intend to
  grant it to — it is a clinical safety permission.

### 19.3 Incidents

**Route:** `/incidents` (permissions: `view incidents`, `report incidents`,
`manage incidents`, `close incidents`)

Incident statuses: `open` → `investigating` → `resolved` → `closed`. An incident
can link to a case and to a QA review.

The workflow:

1. Report with category, severity and the case reference.
2. Assign corrective actions; each action is completed and then **verified**.
3. Record the resolution, then close only when every action is verified.
4. If the investigation needs a restricted clinical record, evidence access is
   recorded in the audit log.

As Super Administrator, check weekly that:

- no incident is past its escalation due date;
- no incident is `resolved` but unclosed with unverified actions;
- every `critical_fail` QA review has a linked incident or a documented reason
  why not.

---

## 20. Callbacks, referrals and case oversight

### 20.1 Callbacks

**Route:** `/callbacks` (permissions: `view callbacks`, `edit callbacks`)

A callback carries a due time and a record of attempts. Each attempt records the
outcome; a callback is completed, rescheduled or cancelled — never silently
deleted. A callback past its due time appears as overdue on the reports.

Check that overdue callbacks are being worked, not merely re-dated. Re-dating is
a decision and should be visible in the attempt history.

### 20.2 Referrals

**Route:** `/referrals` (permissions: `view referrals`, `edit referrals`)

A referral records the action taken (`action`), the outcome (`outcome`) and any
cancellation reason. The referring clinician's decision must be recorded as
given, not summarised into a different outcome.

### 20.3 Case oversight

With `view all cases` and `review cases` you can read any case, not only your
own. Use it for:

- QA sampling;
- incident investigation;
- a complaint where the caller quotes a date and time;
- checking that a safety screen was completed before a transfer.

You can document a case (`edit cases`) but you must not rewrite another agent's
safety screen to make a report read better. If a record is wrong, add a note
explaining the correction.

### 20.4 Reconciliation exceptions

**Route:** `/diagnostics/correlation-exceptions` (requires call-reading access)

These are calls the console could not fully reconcile with the PBX. Each has a
reason and an owner. Resolve them by re-running the import or recording the
exception with a written explanation — never by deleting the row.

---


## 21. Publishing the role manuals

The three manuals are files in `docs/`, published by `App\Services\GuideLibrary`
and gated by one permission each:

| Manual | File | Permission |
|---|---|---|
| Agent | `docs/EXP_Agent_User_Manual.md` | `view agent guides` |
| Viewer | `docs/EXP_Viewer_User_Manual.md` | `view viewer guides` |
| Super Administrator | `docs/EXP_Super_Admin_User_Manual.md` | `view super admin guides` |

### 21.1 How a manual is rendered

- The markdown file is converted with GitHub-flavoured Markdown; raw HTML is
  escaped and unsafe links are refused.
- The first `#` heading becomes the page title and is removed from the body.
- The printed Contents block is dropped, because the page renders its own sticky
  section navigation from the second-level headings.
- Reading time and last-updated date come from the file itself.

### 21.2 Editing a manual

1. Edit the markdown in `docs/`.
2. Keep heading numbering and the Contents list in step with each other.
3. Re-read the rendered page at `/guides/{key}` — tables are the usual casualty.
4. Run the test suite; `tests/Feature/GuideLibraryTest.php` checks that every
   manual is installed, renders and stays behind its permission.
5. Deploy the file with the release.

There is no upload screen: a manual is part of the release, reviewed like code.
That is intentional. A document that can be edited in production cannot be
audited.

### 21.3 Adding a fourth manual

A new role manual needs a code change, not a database row:

1. Add a constant and metadata entry in `GuideLibrary`.
2. Add the key to the `whereIn` list on the `guides/{guide}` route.
3. Add the file to `docs/`.
4. Create the permission and grant it in `GuideAccessSeeder`.
5. Add an icon mapping in the header and sidebar.
6. Extend `GuideLibraryTest`.

### 21.4 Granting access on an existing environment

After a release that changes manual permissions, run:

```bash
php artisan db:seed --class=GuideAccessSeeder
php artisan permission:cache-reset
```

The seeder creates the three guide permissions, gives all three to **Super
Administrator**, agent and viewer to **Administrator**, agent to **Agent** and
**Supervisor**, and viewer (plus the read-only operational permissions) to
**Viewer**. It is safe to re-run.

### 21.5 The guides menu, header and sidebar

All three entry points read the same `GuideLibrary::navigation()` result, which
is filtered by the signed-in user's permissions. If a manual is missing from one
place, the user lacks its permission — not a caching bug. After granting a
permission, ask the user to reload; navigation is shared on each Inertia visit.

---


## 22. Security, privacy and retention

### 22.1 The rules you enforce

| Rule | Why |
|---|---|
| Least privilege | Every extra permission is a potential data breach |
| Named accounts | The audit log must identify a person |
| Two-factor authentication on administrators | Passwords alone are routinely phished |
| No clinical content in logs | Logs are read by engineers and retained widely |
| Consent before recording | Recording a caller without consent is unlawful |
| Evidence is append-only | A record that can be edited cannot be trusted |
| Restrict, then audit access to sensitive records | Staff should see only what the task needs |

### 22.2 Masking and restricted views

Caller identity and clinical content are masked on call history screens unless
the viewer holds `view cases`. This is why the **Viewer** role is built without
`view cases`, `view pbx` and export permissions: a read-only stakeholder sees
volume and outcome, not the caller's story.

Do not "solve" a masking complaint by granting `view cases` broadly. Ask what
the person actually needs to see, and grant the narrowest permission that
delivers it.

### 22.3 Data subject requests

When a caller asks what the practice holds about them:

1. Identify the records by date, time and number.
2. Ask the clinical lead to confirm what may be disclosed.
3. Export only what is in scope; every export is audited.
4. Record the request and the response outside the console.

### 22.4 Retention

Agree and document retention for:

- case and call records;
- recordings, if any;
- audit and integration logs;
- reports and saved runs;
- backups.

Deleting records is an operational decision with clinical and legal
consequences. Never delete to make a report cleaner.

### 22.5 Secrets

- Application, database, PBX and mail credentials live in the environment file
  or your secret manager, never in the repository, a manual or a ticket.
- Rotate the PBX credential and the application key only with a planned
  maintenance window and a tested restore.
- `APP_DEBUG` must be **off** in production: debug pages leak configuration.

---

## 23. Backup, recovery and release routine

### 23.1 What must be backed up

1. **Database** — cases, calls, audit, reports, users and roles.
2. **Environment file** — secrets and connection settings.
3. **Uploaded files and exports**, if the installation stores any.
4. **`docs/`** — the manuals are part of the release, but keep them with the
   backup so an offline copy is always available.

Back up the database **and** the environment file together; a database restored
without its `APP_KEY` cannot decrypt encrypted columns.

### 23.2 Testing a restore

At least twice a year, restore into a staging environment and verify:

- an administrator can sign in;
- call history and case records open;
- the service window state is correct;
- the dentist directory and numbers are present;
- the audit log is intact to the restore point.

A backup that has never been restored is a hope, not a backup.

### 23.3 Release routine

```bash
# 1. Take a database backup.
# 2. Deploy the code and the docs/ files.
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=GuideAccessSeeder
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm ci && npm run build
php artisan queue:restart
```

Then:

1. Run **AMI health → Run health check**.
2. Open **Connection health** and confirm PBX, Redis and queue are current.
3. Sign in as an Agent (or use a test account) and open the workspace.
4. Open **Guides** and confirm the manuals you expect are listed.
5. Check **Audit log** for the release activity.

### 23.4 Rollback

Keep the previous release artefact and the pre-release database backup. A
rollback is code **plus** database; restoring only one of them usually makes
things worse. Record what was rolled back and why.

---


## 24. Troubleshooting

| Symptom | First check | Then |
|---|---|---|
| A user cannot sign in | Is the account **Active**? | Reset the password; verify the email; check the two-factor clock |
| A user signs in but sees only Dashboard | Role assigned? | **Users & roles → Roles**; check the role's permissions |
| A menu is missing for one person only | Role permissions | Compare with a colleague in the same role; reset the permission cache |
| Every screen returns 403 after a release | Seeder ran? | `php artisan db:seed --class=GuideAccessSeeder`, then reset the permission cache |
| A guide returns 404 | Manual file present in `docs/`? | Deploy the file; check the `whereIn` list on the route |
| A guide returns 403 | Does the role hold its guide permission? | Grant it in **Roles & permissions** |
| **AMI health** says unhealthy | Connection status and heartbeat | Restart the listener; confirm host/port; re-run the check |
| **Connection health** shows a stale dependency | Which row? | PBX → telephony; Redis → cache/queue service; queue → worker |
| Calls arrive but the console shows no live state | AMI listener | Integration logs; restart listener; check credentials |
| Yesterday's calls missing from reports | CDR/CEL import | Integration logs; re-run the import; check the importer worker |
| A report is empty | Definition's KPI permissions | Ask which grant the definition needs; check the date window |
| Save run creates unexpected revisions | Definition changed | Review the revision history before quoting either figure |
| A dentist is missing from transfer selection | Approved number, credential expiry, roster, availability | Fix the first failing condition |
| A dentist number cannot be deleted | History attached | Block the number instead; history must keep its reference |
| PBX connection delete is refused | Interactions or telemetry gaps exist | Update the configuration; do not force delete |
| Service state is wrong | Service hours and holidays | Check today's row, `is_closed`, and the effective dates |
| A user was edited and lost permissions | Roles are replaced on save | Re-tick every role the person should hold |
| Audit entries are missing | Retention or period | Confirm the period; check backup retention |
| A manual update is not visible | Release deployed? | Deploy `docs/`; confirm the reading time changed on the card |

### 24.1 Escalation

Escalate to the telephony engineer when AMI will not connect or the PBX rejects
credentials. Escalate to the application developer when a permission check
behaves differently from this manual, or when a screen shows an error page rather
than a message. In both cases include: the time, the account, the screen, and the
matching integration-log or structured-log entries.

---

## 25. Daily, weekly and monthly checklist

### 25.1 Start of day

- [ ] Dashboard shows the expected service state.
- [ ] **AMI health** check passes; heartbeat is fresh.
- [ ] **Connection health**: PBX, Redis and queue current.
- [ ] No error burst overnight in **Integration logs**.
- [ ] Yesterday's CDR/CEL imported and reconciliation flags reviewed.
- [ ] Rosters and availability match today's clinical cover.

### 25.2 End of day

- [ ] Open incidents reviewed; escalations assigned.
- [ ] Overdue callbacks listed for tomorrow's shift.
- [ ] Audit log reviewed for unplanned user or role changes.
- [ ] Tomorrow's rosters and any leave windows confirmed.
- [ ] Any manual change recorded in the handover note.

### 25.3 Weekly

- [ ] Incidents: none past escalation due; corrective actions verified.
- [ ] QA: `critical_fail` reviews have a linked action or reason.
- [ ] Training: expiring certifications flagged to the shift lead.
- [ ] Access: any account with a new privileged permission justified.
- [ ] Reports: weekly operations report reviewed with the shift lead.
- [ ] Guides: all three manuals open cleanly for their audiences.

### 25.4 Monthly

- [ ] Dentist directory review (section 12.6).
- [ ] Access review (section 7.4).
- [ ] Credential expiries for the next 60 days.
- [ ] Restore test on a staging environment (quarterly is the minimum).
- [ ] Retention check: audit, integration logs and backups.
- [ ] Manual review: does this guide still match the screens?

---


## 26. Glossary

| Term | Meaning |
|---|---|
| **AMI** | Asterisk Manager Interface; the PBX's control and event channel |
| **Agent** | The call-centre role that answers and documents calls |
| **Callback** | A promised return call, with a due time and attempt history |
| **CDR** | Call Detail Record; one row per call leg |
| **CEL** | Channel Event Log; the PBX's channel-level event stream |
| **Certification** | A recorded training pass; a current certification (or `create cases`) authorises opening cases |
| **Correlation exception** | A call the console could not fully reconcile with the PBX |
| **DID** | Direct Inward Dialling; the public number a caller dials |
| **Disposition** | The recorded outcome of a case or call |
| **Heartbeat** | The listener's periodic proof of life; stale after 90 seconds by default |
| **IVR** | Interactive Voice Response; the menu a caller hears |
| **KPI** | A defined, measurable indicator with a numerator and denominator |
| **Least privilege** | Granting the smallest permission set that does the job |
| **P1 / P2 / P3** | Emergency, urgent and routine priorities |
| **QA** | Quality assurance review of a sampled case |
| **SBAR** | Situation, Background, Assessment, Recommendation — the handover structure |
| **Service window** | The hours the line is open, taken from Service hours |
| **Telemetry gap** | A period where the console knows events are missing |
| **Warm transfer** | Introducing the caller to the dentist before releasing the call |

---

## 27. Quick reference card

### 27.1 Key routes

| Screen | Route | Permission |
|---|---|---|
| Administration hub | `/administration` | any admin permission |
| Users | `/admin/users` | `view users` |
| Roles | `/admin/roles` | `view roles` |
| Permission catalogue | `/admin/permissions` | `view permissions` |
| Sessions | `/admin/sessions` | `view sessions` |
| Settings | `/admin/settings` | `view settings` |
| Service hours | `/admin/service-hours` | `view settings` |
| PBX connections | `/admin/pbx-connections` | `view pbx` |
| AMI health | `/admin/ami-health` | `view pbx` |
| Integration logs | `/admin/integration-logs` | `view pbx` |
| Connection health | `/admin/connection-health` | `view pbx` |
| Structured logs | `/admin/structured-logs` | `view pbx` |
| CDR / CEL | `/admin/cdr-cel` | `view pbx` |
| Audit log | `/admin/audit` | `view audit` |
| Questionnaires | `/screening-protocols` | `manage screening protocols` |
| Reports | `/reports` | `view reports` |
| Guides | `/guides` | any guide permission |

### 27.2 The three manuals

| Manual | URL | Audience |
|---|---|---|
| Agent role guide | `/guides/agent` | Agents and supervisors |
| Viewer role guide | `/guides/viewer` | Read-only viewers |
| Super Administrator role guide | `/guides/super-admin` | Platform owners (this manual) |

### 27.3 Five things never to do

1. Never share an administrator account or password.
2. Never grant a permission "just in case".
3. Never edit a call, case, CDR or CEL record to improve a figure.
4. Never disable two-factor authentication to work around a lockout.
5. Never delete a role, dentist number or PBX connection that has history
   attached — deactivate or block it instead.

### 27.4 Five things to do every day

1. Confirm the AMI heartbeat and connection health.
2. Confirm yesterday's calls imported and reconciled.
3. Read the audit log for unplanned changes.
4. Confirm today's rosters and availability.
5. Leave the shift with a written handover of anything you changed.

---

*End of the Super Administrator role guide. If this guide and the live console
disagree, the console is the source of truth for behaviour and this guide is
wrong — report it so the next release corrects it.*

