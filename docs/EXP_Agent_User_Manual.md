# EXP Operations — Agent User Manual

**Audience:** Call-centre agents (role: **Agent**) working the EXP dental line
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
6. [How a call reaches you](#6-how-a-call-reaches-you)
7. [Answering a live call](#7-answering-a-live-call)
8. [Opening a case](#8-opening-a-case)
9. [Step 1 — Consent and privacy](#9-step-1--consent-and-privacy)
10. [Step 2 — Caller details and reported concern](#10-step-2--caller-details-and-reported-concern)
11. [Step 3 — Safety screen](#11-step-3--safety-screen)
12. [Priorities explained](#12-priorities-explained)
13. [Step 4 — SBAR handover](#13-step-4--sbar-handover)
14. [Step 5 — Transferring to a dentist](#14-step-5--transferring-to-a-dentist)
15. [Step 6 — Disposition and closing the case](#15-step-6--disposition-and-closing-the-case)
16. [Notes and case history](#16-notes-and-case-history)
17. [Shift handover](#17-shift-handover)
18. [Call history screens](#18-call-history-screens)
19. [Reading a call record](#19-reading-a-call-record)
20. [Dentist directory (read-only)](#20-dentist-directory-read-only)
21. [Privacy, consent and evidence rules](#21-privacy-consent-and-evidence-rules)
22. [Troubleshooting](#22-troubleshooting)
23. [Shift checklist](#23-shift-checklist)
24. [Glossary](#24-glossary)
25. [Quick reference card](#25-quick-reference-card)

---

## 1. About this manual

This manual is the complete operating guide for an EXP agent. It explains:

- what your account can see and do;
- how a call travels from the IVR menu to your screen;
- how to answer, screen, hand over and close a caller's case;
- how to transfer to a dentist and record it honestly;
- how to read call history and the dentist directory;
- what to do when something looks wrong.

Two principles run through the whole system:

1. **The console records evidence, it does not guess.** If the PBX did not report a key press, the screen says *"option not recorded"*. If a dentist did not accept a call, the call is shown as *unverified* or *not connected*. Never "tidy up" a record to look better than the evidence.
2. **Jitsi is the telephony control; EXP is the record.** Answer, hold, dial and hang up in Jitsi unless your supervisor has enabled browser call controls for your account. Either way, every action you record in EXP must match what actually happened on the call.

If anything in this guide disagrees with what your supervisor tells you during a live incident, follow your supervisor — then ask them to have the guide corrected.

**Related manuals.** This is one of three role manuals. The *Viewer role guide*
covers the read-only screens and the *Super Administrator role guide* covers
administration and integration health. Each is visible only to the roles that
hold its permission, so you may not see the other two in your **Guides** menu —
that is expected.

---

## 2. Your role and what you can open

The **Agent** role is deliberately narrow. It grants exactly these permissions:

| Permission | What it lets you do |
|---|---|
| `view cases` | Open the Agent Workspace and read case records you are allowed to see |
| `edit cases` | Record consent, caller details, safety screens, notes and dispositions |
| `control calls` | Use the browser **Call controls** panel on a case you own (when enabled) |
| `view call history` | Open **Calls → Agent calls**, **DID journeys** and **All calls** |
| `view dentists` | Read the dentist directory, numbers, credentials, rosters, availability and performance |
| `view agent guides` | Open this manual from **Guides**, or from the guide link in the header/sidebar footer |

What this means in practice:

- Your menu shows **Dashboard**, **Agent Workspace**, **Calls** (with three sub-pages), **Dentists** and **Guides**.
- You **cannot** open Administration, Reports, Callbacks, Referrals, Knowledge Bank, Quality Assurance, Training or Incidents unless your account is given extra permissions. If a colleague has one of those menus and you do not, that is normal.
- You only see **cases assigned to you**. There is no shared queue of other agents' cases; `view all cases` is a supervisor permission, not an agent one. Opening a new case assigns it to you.
- **Opening a new live case requires a current live-call certification.** Without one you can still read and document existing cases, but *Open case* will be rejected with a 403 message. Your supervisor manages certifications.

> **If a menu or button is missing,** assume it is a permission setting, not a bug. Ask your supervisor or administrator — do not attempt workarounds.

---

## 3. Getting set up

### 3.1 Before your first shift

1. **Account** — your supervisor creates your user with the **Agent** role and a verified email address.
2. **Email verification** — the console requires a verified email before any operational screen opens. Click the link in the verification email on first sign-in.
3. **Two-factor authentication (2FA)** — enabled per account from **Settings → Security**. You will be asked for a 6-digit code from your authenticator app (or a passkey prompt) after your password.
4. **Headset and Jitsi** — confirm your headset works in Jitsi before the service window opens. Test the microphone and earpiece with a colleague.
5. **Browser** — use a current Chrome, Edge, Firefox or Safari. Do not run the same case in two tabs (see §22.6).
6. **Time zone** — most timestamps in the console are shown in UTC and labelled as such. The service window is always labelled in Ghana time. Read the label; do not convert by hand.

### 3.2 Signing in

1. Open the console URL given by your administrator.
2. Enter your **email** and **password**, then **Log in**.
3. If 2FA is enabled, enter the 6-digit code or approve the passkey prompt.
4. You land on the **Dashboard**. Your name and menu appear in the sidebar.

**Forgot your password?** Use **Forgot your password?** on the sign-in screen. A reset link is emailed to you. If it does not arrive within a few minutes, check spam, then ask your administrator to confirm the address on your account.

### 3.3 Your settings

Open **Settings** from the sidebar footer:

- **Profile** — name and email address.
- **Security** — change password, enable/disable 2FA, manage passkeys. Changing security settings asks you to re-enter your password first.
- **Appearance** — light/dark/system theme. Dark mode is fully supported; use whichever keeps you comfortable during long shifts.

### 3.4 Signing out

Always sign out at the end of a shift, especially on a shared workstation: sidebar footer → **Log out**. Administrators can also review and terminate active sessions.

---

## 4. Finding your way around

| Menu item | Where it goes | What you use it for |
|---|---|---|
| **Dashboard** | `/dashboard` | Live PBX health, today's call snapshot and shortcuts. Landing page after sign-in. |
| **Agent Workspace** | `/workspace` (also `/cases`) | The active-call queue and the case list. This is your main working screen. |
| **Calls → Agent calls** | `/calls/agent` | Every call that reached the agent menu option or an agent leg, with stats and journey. |
| **Calls → DID journeys** | `/calls/did` | Every call to the assigned DID, including self-service calls, with the menu option pressed. |
| **Calls → All calls** | `/live-operations/calls` | Full reconciled call list with filters and (if permitted) export. |
| **Dentists** | `/dentists` | Read-only directory: numbers, credentials, rosters, availability, performance. |
| **Guides → Agent guide** | `/guides/agent` | This manual, with an "On this page" index. The Viewer guide is only shown to accounts that hold `view viewer guides`. |
| **Administration** | `/administration` | Only visible if your account holds administration permissions; agents normally do not. |

On any phone-related screen you will see the same two badges at the top:

- **Service window badge** — `Open · Mon–Fri 10:00–14:00` or `Closed · …`, with the next opening time when closed.
- **PBX badge** — `PBX live`, `PBX stale` or `PBX offline`, plus heartbeat age and your agent extension.

### 4.1 The Dashboard

The Dashboard is a health snapshot, not a work queue:

- connection status and last error for the PBX connection;
- today's call counters;
- currently active calls table (caller, stage, duration);
- a link into the live operations view for deeper detail.

If the Dashboard shows a PBX error, tell your supervisor **before** the next call and continue answering in Jitsi.

---

## 5. The service window

The published window is:

> **Monday to Friday, 10:00–14:00 Ghana time. Closed on Ghana public holidays.**

How it behaves in the console:

- The window is stored once as the **Standard Hours** service record and is shown on **Agent calls**, **DID journeys** and **Dentist rosters**.
- **Weekends** are outside the window automatically.
- **Ghana public holidays** come from the service calendar (for example New Year's Day, Independence Day, Eid, Christmas). A weekday holiday closes the window even though the weekday is a working day.
- Rosters are generated **only for service days**: 10:00–14:00 shifts, skipping weekends and public holidays, and never duplicating a shift that already overlaps.

What this means for you:

1. **A closed badge does not mean the phones are dead.** Callers can still reach the line outside the window; the badge tells you the published service expectation. Answer and record exactly as usual.
2. **Dentist availability is a scheduling statement, not a promise.** Outside the window, or on a holiday, most dentists will not be rostered. The transfer directory will show that honestly — do not tell a caller a dentist "will call back now" if nobody is rostered.
3. **Never edit the window yourself.** Only administrators change service hours. If the window looks wrong (for example a holiday missing), report it; do not work around it.

---

## 6. How a call reaches you

Every call to the EXP DID hears the same recorded menu:

| Key | Option | What happens |
|---|---|---|
| **1** | Listen to the recorded dental health message | Self-service recording. No agent, no case. |
| **2** | Request a callback from the EXP team | Self-service callback request. No agent contact on this call. |
| **3** | Speak to an EXP agent | **This is your queue.** The call is routed to an agent for screening. |

The agent option is configurable (`EXP_IVR_AGENT_OPTION`, default **3**). If it changes, the menu labels on **DID journeys** and **Agent calls** change with it — always read the label on screen instead of assuming.

### 6.1 What the console records about the journey

The console only shows what the PBX actually reported:

- **Key presses** (`Option 3 · Speak to an EXP agent`) — shown as recorded steps with times.
- **When no key press was captured**, the journey says **"Menu option not recorded"**. That is a statement about the evidence, not about the caller. Do not tell a caller or a supervisor that they "didn't press anything".
- **Agent contact** — shown when the caller pressed the agent option *or* reached an agent leg. A case can only be opened from a call with verified agent evidence.

### 6.2 Two screens, two questions

- **DID journeys** answers: *"What happened to every call that hit our line?"* — including people who only listened to the recording.
- **Agent calls** answers: *"Which calls reached me, and what did we do with them?"* — the default evidence basis is *menu option 3 or agent leg*; you can widen it to *also include case-only history*.

Use DID journeys when a caller says "I called earlier and nobody helped me" — it shows whether they pressed 3, chose the recording, or the key press was never captured.

---

## 7. Answering a live call

### 7.1 The order of operations

1. **Answer in Jitsi first.** The EXP console is not the phone. Pick up the call in Jitsi, greet the caller, and confirm you can hear them.
2. **Confirm the caller verbally.** Ask for the name they want to be called and the reason for calling. A number on a screen does not identify a person.
3. **Open the Agent Workspace** (`/workspace`) and check that the call appears under **Incoming and active calls**.
4. **Associate the case** (§8) before you start typing details. Recording against the wrong call is a data-protection incident.
5. Work the case in order: consent → caller details → safety screen → handover/transfer → disposition.

### 7.2 The "Incoming and active calls" panel

Each active call card shows:

| Field | Meaning |
|---|---|
| `#id · correlation_key` | The interaction id and its correlation key. Quote the correlation key to your supervisor when reporting an issue. |
| **Stage badge** | Where the call is now, e.g. *Ringing*, *Answered*, *On hold*, *Bridged*. |
| **Exception count** | Rose badge when the PBX reported correlation exceptions. Investigate before trusting the card. |
| **EXP / Unverified** | Whether EXP has verified the call as an EXP journey. |
| **Caller number** | Always masked — caller IDs are never stored in full. |
| **Inbound line** | Which DID the call arrived on. |
| **Agent extension / channel** | Your extension that took the call. |
| **Ringing / waiting / duration** | Timing evidence, with a timing-quality indicator. |

Below the cards you also get a **recent journeys** list — calls that just ended. It helps when a caller hangs up and immediately calls back.

### 7.3 PBX freshness — read it before you trust the screen

The panel always shows the PBX freshness state:

| State | What it means | What you do |
|---|---|---|
| **live** | Heartbeat and events are current. | Normal operation. |
| **stale** | The listener has not reported recently; prompts may lag. | Do **not** conclude "no call is waiting". Check the switchboard/Jitsi, tell your supervisor if it persists. |
| **offline** | No usable telemetry. | Work entirely from Jitsi and tell your supervisor immediately. |

When the state is not *live*, the panel shows an amber warning. Read it — it exists to stop you from acting on missing data.

### 7.4 Controls on the panel

- **Pause live call prompts / Resume live call prompts** — stops or restarts the 3-second refresh. Pause while you are reading a long note; resume as soon as you are done, otherwise new calls will not appear.
- **Refresh calls** — one-off reload of call evidence and the case list.
- Filter links at the top: **All accessible cases**, **My open cases**, **Clinical review**, **Closed / dispositions**.

### 7.5 Ambiguity — when the console is not sure

The console flags ambiguity when:

- **more than one EXP call is active**;
- **the PBX correlation raised an exception**;
- **a call has stayed active for over two hours** (possible missing hang-up).

If the card you selected carries any of these reasons, the *Open case* form requires you to tick **ambiguity acknowledged** as well as **association confirmed**. Only do that after you have positively identified the caller and the call (for example by reading back a detail, or confirming with your supervisor).

---

## 8. Opening a case

A case is the record of one caller's contact. One call = one case. Do not open a case for a test call, a wrong number, or a colleague's call.

### 8.1 Prerequisites

- Call is active in Jitsi and you have spoken to the caller.
- The interaction is verified as an EXP call and has observed agent-channel evidence.
- You hold a current live-call certification.
- You are certain which active call is the caller's.

### 8.2 Opening

1. In the Agent Workspace, find the call under **Incoming and active calls**. If exactly one eligible call exists, it is selected automatically.
2. Click the card or choose it in the **Open a case** form.
3. Tick **I have confirmed this call belongs to this caller** (association confirmed). This is a legal attestation, not a formality.
4. If the *ambiguity acknowledged* checkbox appears, tick it only after you have resolved the ambiguity (§7.5).
5. Click **Open case**.

### 8.3 What happens next

- The case is created and **assigned to you**.
- Your association check is written to the audit log with the interaction id and any ambiguity reasons.
- You are taken straight to the case record (`/cases/{id}`).
- If a case already exists for that interaction, the console opens the existing case instead of creating a duplicate.

### 8.4 Errors you may see

| Message | Meaning | Action |
|---|---|---|
| *An active live-call certification is required before opening a case.* | Your certification expired or was never issued. | Read/document only, tell your supervisor. |
| *Verified EXP and observed Assistant channel evidence is required.* | The call is not verified as an EXP call, or the agent leg was never observed. | Re-check the call card; if it is a genuine call, escalate to your supervisor. |
| *More than one call is active or the PBX evidence conflicts. Confirm the exact interaction before opening this case.* | Ambiguity flag without acknowledgement. | Identify the call positively, then tick ambiguity acknowledged. |
| *404 — page not found* | The case is not assigned to you (or no longer exists). | Do not retry; ask your supervisor to check case assignment. |

---

## 9. Step 1 — Consent and privacy

The case record is a list of numbered sections. Work top to bottom; later sections stay disabled until consent is recorded.

### 9.1 The consent section

It shows:

- the **privacy notice version** currently in force (title, version, and the full notice text);
- the last recorded consent status and whether callback permission was granted;
- the consent form.

### 9.2 Recording consent

1. Read the privacy notice to the caller (or paraphrase it faithfully — do not shorten away the purpose of the call or how the record is used).
2. Choose **Permission to proceed**:
   - **granted** — the caller agrees to screening and to the record being created;
   - **declined** — the caller refuses;
   - **unable** — the caller cannot give consent (confused, impaired, language barrier, third party);
   - **withdrawn** — the caller is withdrawing consent previously given (see §9.4).
3. Tick **Permission to use a confirmed number for callback** only if the caller explicitly agrees to be called back.
4. Click **Record consent status**.

Notes:

- Consent is always recorded against the **version currently published**. Historical cases keep the version they were recorded against; never try to "update" an old notice version.
- Without **granted** consent, the caller-details form, safety screen and notes remain disabled. That is intentional: no consent, no screening.
- Declined / unable / withdrawn do not leave a half-open case. Close it with the appropriate disposition (§15).

### 9.3 What "granted" unlocks

- Caller details and reported concern
- Safety screen
- Factual notes
- Disposition options that require consent (urgent referral, routine handover, service FAQ)

### 9.4 Withdrawing consent

Use **Record withdrawal of existing consent** when a caller who previously agreed asks to withdraw.

- The withdrawal is recorded against the **same notice version** as the original consent.
- Withdrawal is allowed even after a case is closed — it is the only change permitted on a closed case.
- Do not edit earlier answers to "undo" consent. The history must show granted, then withdrawn.

---

## 10. Step 2 — Caller details and reported concern

This section records the **minimum** needed to help the caller. Confirm everything with the caller; never assume the incoming number identifies the person.

| Field | Required | Guidance |
|---|---|---|
| **Preferred name** | Optional | The name the caller wants to be called. First name or pseudonym is fine. |
| **Confirmed Ghana callback number** | Optional | Read it back digit by digit. If the caller is not the number owner, say so in the notes. |
| **Age band** | Required | *Not recorded*, *Adult*, or *Child*. |
| **Guardian name / relationship** | Only for children | Required when *Child* is selected and the questionnaire allows the guardian flow. |
| **Guardian relationship confirmed** | Only for children | Tick only after using the approved guardian process. |
| **Contact type** | Required | *genuine*, *unclear*, *abandoned*, *silent*, *nuisance*, *ultra_short*. |
| **Concern type** | Required | *Personal clinical concern* or *Service information only*. |
| **Concern** | Required before routing/closing | The caller's own words, in one or two sentences. |
| **Onset** | Optional | When it started ("three days ago", "since the extraction on Monday"). |
| **Impact** | Optional | How it affects eating, sleeping, work, school. |

Click **Save caller and concern**.

### 10.1 Contact type — choose the honest one

- **genuine** — a real caller with a real reason.
- **unclear** — you cannot tell (language, background noise, third party).
- **abandoned** — the caller hung up before you could speak properly.
- **silent** — connected but no speech.
- **nuisance** — abusive, prank, or repeated non-genuine calls.
- **ultra_short** — a very short call with no meaningful content.

Contact type and call duration never suppress an emergency answer. A 20-second call can still be P1.

### 10.2 Concern type — it changes the available dispositions

- **Personal clinical concern** — can be P1/P2/P3/Review. It can **never** be closed as a service FAQ.
- **Service information only** — may be resolved as FAQ (priority **S**), or escalated if the caller reveals a clinical issue. If that happens, change the concern type before recording the safety screen.

---

## 11. Step 3 — Safety screen

Every caller who grants consent is screened with the **active questionnaire version**. Short or unusual calls do not bypass safety.

### 11.1 Before you start

- The section shows the protocol title and version in use.
- Questions appear in order. A question with a condition (for example a follow-up after "Yes") only appears when the earlier answer triggers it.
- A red asterisk marks required questions.
- Help text under a question explains what to ask or how to interpret the answer.

### 11.2 Answering

1. Ask each question exactly as written; do not merge or reorder them.
2. Record the answer: **Yes / No / Unknown** for yes-no questions, the listed options for choice questions, or the caller's words for text/paragraph questions.
3. Use **Unknown** when the caller genuinely does not know — never guess, and never leave a required question blank to move on.
4. Select the **priority** you believe fits (§12). The console may raise it automatically based on the answers; it never lets you lower it without a clinical reviewer.
5. Click **Record safety screen**.

### 11.3 Automatic priority rules

- A positive answer flagged as **emergency** forces **P1** immediately. You do not need to finish the questionnaire.
- A positive answer flagged as **urgent** forces at least **P2**.
- Any **Unknown** answer, or any missing required answer, sets the priority to at least **Review** (clinical review).
- **S** is only possible when the concern type is *Service information only*.
- If an earlier screening already set a higher priority, lowering it requires the **clinical reassessment reason** field (only reviewers see that field) plus a complete, non-unknown, non-positive reassessment.

### 11.4 Drafts, autosave and conflicts

The safety screen autosaves your answers as a **draft** while you type. This protects you if the browser refreshes mid-call.

| Indicator | Meaning | Action |
|---|---|---|
| *Draft saved · revision N* | Answers are safely stored but **not yet recorded**. | Keep going; record the screen when finished. |
| *Saving draft…* | Autosave in flight. | Wait a second before recording. |
| *Unsaved answers were recovered from this browser after a refresh* | The browser restored local answers and is saving them again. | Re-read each answer against what the caller actually said, then record. |
| *These answers were saved in another window. Reload the case before continuing.* | Two tabs/windows edited the same draft. | Click **Discard this window's unsaved answers and reload the case**. |
| **Retry saving answers** button | Autosave failed (network). | Click retry; if it keeps failing, write the answers down and tell your supervisor. |

**Drafts are not screens.** A draft never counts as a completed safety screen, and a case cannot be closed on the strength of a draft.

### 11.5 Common validation messages

| Message | Cause | Fix |
|---|---|---|
| *Answer every required safety question, or record unknown.* | A required answer is blank and there is no emergency. | Answer it, or record *Unknown*. |
| *Record a valid answer for "…"* | Text typed into a choice/yes-no question, or an over-long answer. | Use the listed option. |
| *Answer the earlier question before recording "…".* | A conditional follow-up was answered while the trigger was blank. | Answer the trigger question first. |
| *Answers must belong to the selected protocol version.* | A protocol was replaced mid-call. | Reload the case and re-answer against the current version. |
| *Personal clinical concerns cannot be resolved as a service FAQ.* | Priority **S** chosen for a clinical concern. | Change the concern type or raise the priority. |
| *Earlier safety findings require a complete reassessment and a clinical reviewer reason before lowering priority.* | You tried to lower a priority set by earlier answers. | Escalate to a reviewer; do not force it. |

---

## 12. Priorities explained

The priority drives the allowed dispositions, the handover guidance and what the caller is told. The console enforces a ranking: **P1 > P2 > Review > P3 > S**. You can raise a priority freely; lowering one requires a clinical reviewer.

| Priority | Name | Typical situation | Must happen |
|---|---|---|---|
| **P1** | Emergency | Uncontrolled bleeding, facial swelling affecting breathing or swallowing, trauma, severe pain with systemic signs, any emergency-flagged positive answer. | Approved emergency instructions given, **and** supervisor / Lead Dentist notified. Never wait for dentist availability. |
| **P2** | Urgent | Urgent but not immediately life-threatening: significant pain, infection signs, broken tooth with pain, post-extraction problems. | Urgent referral / priority clinician handover, with consent and a recorded concern. |
| **P3** | Routine | Routine clinical questions, follow-up, non-urgent symptoms. | Routine clinician handover **or** a callback request with a confirmed number and permission. |
| **S** | Service / FAQ | Opening hours, location, price list, service information — no personal clinical concern. | FAQ confirmed as resolving the request. |
| **Review** | Clinical review | Incomplete screen, *Unknown* answers, unclear presentation, or anything you are not authorised to classify. | Case stays in **clinical review** with a next step; a reviewer decides. |

### 12.1 Choosing the priority honestly

- Base it on the **answers**, not on how the caller sounds or how long the call is.
- If you are unsure between two, choose the **higher** one. The console may raise it for you; that is intended.
- **Never** pick **S** for anything clinical. The system will reject it.
- **Review** is a legitimate outcome. "I don't know" is a valid clinical position; a wrong "routine" is not.

### 12.2 Changing a priority later

- A new screening can raise the priority.
- Lowering it is blocked unless a reviewer records a reason and the reassessment is complete with no unknown or positive answers.
- If a caller's condition worsens mid-call, record a **new** safety screen rather than editing the old one. History is append-only by design.

---

## 13. Step 4 — SBAR handover

The case builds the handover text for you from what you have recorded:

```
Situation:  Case #123; P2; "Swelling on the left side since Saturday".
Background: adult; onset 3 days; impact cannot chew; callback ending 4567.
Assessment information (caller-reported facts; no diagnosis):
            Is there swelling around the eye or jaw? Yes
            Is breathing or swallowing affected? No
            ...
Request:    Clinician assessment / approved next step to be recorded.
```

How to use it:

1. **Copy handover** — copies the text to your clipboard for pasting into Jitsi chat, a referral form, or your supervisor message.
2. **Open dentist directory** — jumps to the read-only directory if you need to confirm who is rostered.
3. **Guidance for the priority** — under the card, the questionnaire's approved guidance for the selected priority is displayed. Read it before you speak to the caller or the dentist.
4. **FAQ answer** — for priority **S**, the approved service answer is displayed. Use it verbatim-ish; do not improvise clinical advice.

Rules:

- The handover contains **caller-reported facts**, not your diagnosis. Never add a diagnosis or a treatment plan.
- If the card says *"Safety screen not yet recorded."*, the handover is incomplete — go back and record the screen.
- The callback line only shows the **last four digits** of the number, on purpose.
- Copy the handover **before** you dial the dentist, so you can read it accurately during the private briefing.

---

## 14. Step 5 — Transferring to a dentist

### 14.1 The transfer directory

The **Transfer directory** panel appears on the case (except for **P1**, where you never wait for a dentist). It lists:

- every **active** dentist with an approved transfer number;
- each dentist's **manual availability**: `available`, `busy`, or `unavailable`;
- when availability was recorded and until when;
- each **masked** number, its label, and whether it is the primary number.

Important:

- **The transfer directory is not a roster view.** It lists dentists with approved numbers and shows their manual availability; it does not prove they are on shift.
- **Availability is a person's scheduling statement, recorded by hand. It is never inferred from call activity.** "Available" does not guarantee the dentist will pick up, and being rostered never confirms a personal mobile is reachable.
- **Numbers are masked here.** Dial from the PBX/Jitsi keypad using the number record; never read a full number aloud or copy it into a message.
- If the panel says *"No dentist currently has an approved transfer number"*, do not improvise a number. Escalate or record a callback request.

### 14.2 Manual transfer in Jitsi (default)

1. Copy the **SBAR handover** first.
2. Put the caller on hold (or ask them to hold) using Jitsi.
3. Dial the dentist's approved number from the directory.
4. Give a **private briefing** using SBAR. Do not transfer the caller before the dentist is on the line and has heard the briefing.
5. Wait for an explicit human acceptance ("yes, put them through").
6. Complete the attended transfer so the caller and dentist are connected.
7. Release yourself only after the bridge is established.
8. Return to the case and record the disposition (§15).

### 14.3 Browser call controls (if enabled for your account)

The **Call controls** panel is available only when **all** of these are true:

- the case is **open**;
- you are the **assigned agent**;
- your account holds `control calls`;
- the PBX gateway is configured and approved.

If any condition fails, the panel tells you to continue in Jitsi. That is expected, not an error.

**States and allowed actions**

| Verified state | Allowed action(s) |
|---|---|
| Incoming call | **Answer call** |
| Agent connected | **Hold caller** |
| Caller on hold | **Resume caller**, **Dial dentist** |
| Dialling dentist | **Cancel dentist attempt** |
| Dentist ringing | **Cancel dentist attempt** |
| Dentist connected | **Begin private briefing**, **Cancel dentist attempt** |
| Private briefing | **Complete attended transfer**, **Cancel dentist attempt** |
| Caller and dentist connected | **Release agent** |
| Completed | No actions |

**Rules the panel enforces for you**

- Only **one command at a time**. While a command is *pending*, every other button is disabled until the PBX confirms or rejects it.
- **Verified state only.** If the call changed, you get *"The call changed. Refresh before trying another action."*
- **Complete attended transfer** requires you to tick *"I privately briefed a human dentist who accepted this caller."* and requires verified dentist answer evidence. The console refuses if the dentist never actually answered.
- **Emergency (P1) blocks dialling and completing transfers.** For P1 you give emergency instructions and notify your supervisor instead.
- For **dial dentist**, pick a dentist whose status is `available`; the picker disables `busy` and `unavailable` dentists.
- Every command is listed with its status (`pending`, `confirmed`, `rejected`) and the masked destination. Use that list when explaining what happened.

### 14.4 When the dentist does not answer

1. Use **Cancel dentist attempt** (browser) or hang up the dentist leg (Jitsi). The caller returns to hold.
2. Record the outcome honestly in the case notes — for example *"Dialled Dr X (primary); no answer after 30 seconds; attempt cancelled."*
3. Tell the caller what is happening and what will happen next.
4. Choose another approved, available dentist, **or** record a **callback request** (P3) if that is what the caller prefers and consent allows.
5. If no dentist is reachable for an urgent (P2) caller, escalate to your supervisor / Lead Dentist — do not keep the caller waiting silently.

### 14.5 What "verified" means on a transfer

- **Verified** — the PBX reported an answer from an **approved** number, and bridge evidence exists.
- **Unverified bridge evidence** — a bridge exists but the answer cannot be matched to an approved number. Treat it as unproven; note it and tell your supervisor if the caller says they spoke to a dentist.
- **No connected dentist** — no answer evidence. Record the next step, not a transfer.

Never mark a transfer as complete because the dentist *said* they would help. Mark it only when the console shows accepted/connected evidence or when you personally completed the attended transfer.

---

## 15. Step 6 — Disposition and closing the case

The **SBAR handover and disposition** section ends with the closure form. The disposition list is filtered by the case priority; you cannot pick a path that contradicts the safety classification.

| Disposition | Allowed priority | When to use |
|---|---|---|
| **Emergency instructions and supervisor escalation** | P1 | Emergency instructions given and supervisor/Lead Dentist notified. |
| **Urgent referral / priority clinician handover** | P2 | Dentist accepted (or will accept) an urgent referral. |
| **Routine clinician handover** | P3 | Routine handover completed or scheduled. |
| **Callback request recorded** | P3 | Caller wants a callback; number confirmed and permission granted. |
| **Service FAQ resolved** | S | Service-information caller's question answered from the approved FAQ. |
| **Clinical review required** | Review | Incomplete/unknown screening or clinical judgement needed. |
| **Contact ended / consent not granted** | Review | Consent declined/unable, silent/abandoned/nuisance call, or no clinical contact possible. |

### 15.1 Closing steps

1. Choose the **disposition**.
2. Write **Action taken and next step** — what you did and what happens next, with a time if known. This is what the next agent or reviewer reads.
3. Complete the priority-specific confirmations:
   - **P1** — tick *Approved emergency instructions/action completed* **and** *Supervisor / Lead Dentist notified*.
   - **S** — tick *The service FAQ resolved the request*.
4. Click **Record disposition and close** (or **Save review next step** for Review).
5. Confirm the case status badge changes to **closed**, or **clinical_review** for Review.

### 15.2 What the console refuses — and why

| Message | Meaning |
|---|---|
| *This disposition does not match the recorded safety priority.* | You picked a path outside the priority's allowed list. |
| *Complete the safety screen before closing a routine case.* | P2/P3/S closure needs a completed screen (or Review). |
| *Confirm the emergency action and supervisor notification. Never wait for dentist availability.* | P1 closure requires both checkboxes. |
| *Record consent against the screening protocol before routine or urgent handover.* | Consent must be granted on the same protocol version as the screen. |
| *Capture the caller-reported concern before closure.* | The concern field is blank. |
| *A callback request requires a confirmed number and callback permission.* | Add the number and permission, or choose another disposition. |
| *A personal clinical concern cannot be closed as a service FAQ.* | Use a clinical disposition. |
| *Confirm that the service FAQ answered the request.* | Tick the FAQ confirmation. |
| *This case changed. Reload it before saving to avoid overwriting another operator.* | Another user saved first. Reload, re-read, re-apply your change. |
| *This case is closed. Only withdrawal of existing consent may be recorded.* | Reopening is not an agent action; escalate. |

### 15.3 Review cases

When the outcome is **Clinical review**:

- the case status becomes `clinical_review`, not `closed`;
- the next step you write is the instruction for the reviewer;
- add a **factual note** with anything the reviewer should know (for example what you could not establish);
- do not re-screen repeatedly hoping to reach a "closure" priority — a review is the correct result for uncertainty.

### 15.4 Callbacks, abandoned and silent calls

**Callback requests (P3).** Choosing *Callback request recorded* records the caller's **intent** and the confirmed number. Scheduling and delivery happen in the follow-up process, not on this screen.

- Only select it when the number is confirmed **and** callback permission is ticked against consent.
- Tell the caller the truth: *"I've recorded your request and passed it to the team; they will call you on this number."* Do not invent a time unless your supervisor has given you one.
- If the caller needs clinical advice urgently, a callback is the wrong path — use P2 and transfer.

**Abandoned, silent or nuisance calls.** If the caller hangs up or there is no meaningful contact:

- choose *Contact ended / consent not granted* (Review);
- write what happened in the next step, including how long you waited;
- never fabricate a screening, and never mark a case as clinically resolved when no clinical conversation took place.

---

## 16. Notes and case history

### 16.1 Factual notes

The **Factual notes** section appends to the case record. Use it for:

- what the caller reported that does not fit a structured field;
- actions taken and their outcome (dialled, no answer, cancelled);
- a callback number that belongs to someone else ("caller using daughter's phone");
- anything a reviewer or the next agent needs to know.

Rules:

- Notes are **factual and attributable**. Write what happened, not opinions about the caller.
- Maximum 2,000 characters per note. Add a second note instead of editing an old one.
- Notes cannot be added before consent is granted, and cannot be edited afterwards. History is append-only.
- Never paste full national numbers, addresses, or clinical documents into a note. Last four digits are enough to identify a callback number.

### 16.2 Screening and consent history

At the bottom of the case:

- **Screening history** — every recorded screen, expandable to show each question, answer, answer status, question version, operator id and save time.
- **Consent history** — every consent event with status, callback permission, notice version and operator.

Use this when a caller disputes what was asked, or when a reviewer needs to see the exact wording in force at the time.

---

## 17. Shift handover

Two supporting screens let a shift end cleanly. They are not in the agent menu by default, but your account can open them when your supervisor gives you the link:

| Screen | Path | Purpose |
|---|---|---|
| **Case ownership and next actions** | `/cases/assignments` | Confirm every unresolved case has an owner, a next action and a due time. Assigning or reassigning closes the previous assignment and keeps the audit trail. |
| **End-of-shift handover** | `/cases/handover` | Record the handover itself. Blocked until every case you own has an active assignment. |

### 17.1 Before you leave

1. Open **Case ownership and next actions**, filter to **My cases**, and check each unresolved case.
2. For any case without a next action, add one with a **due time** — "caller to attend clinic Monday 09:00", "supervisor to review", "callback Wednesday 11:00".
3. Open **End-of-shift handover**:
   - choose the **incoming owner**;
   - tick **Incoming owner acknowledged the handover** once they confirm verbally;
   - write handover notes (what is outstanding, what to watch, any caller expectations);
   - submit.
4. Do not hand over a case with no owner and no next action — the screen will refuse, and rightly so.

> Assignment and handover are how continuity survives a shift change. A verbal "I told someone" is not a handover.

---

## 18. Call history screens

All three screens share the same visual language: a hero header with today's counters, a service-window badge, a filter bar, and a list you can drill into.

### 18.1 Agent calls (`/calls/agent`)

**Stats**

| Tile | Meaning |
|---|---|
| **Today's total** | Calls received on the service day. |
| **Reached the agent** | Callers who pressed the agent menu option or reached an agent leg. |
| **Screened** | Cases with recorded answers. |
| **Connected** | Calls with a **verified** dentist bridge. |

**Filters**

- **From / To** — date range.
- **Evidence** — *Menu option 3 or agent leg* (default) or *Also include case-only history*.
- **Caller number** — partial search, e.g. `233*****34`.
- **Apply filters / Reset**.

**Row contents** — caller, stage/outcome, duration, connection state (`verified`, `unverified`, `none`), flags, and a link into the call record. Expand a row to see the journey chips and the case link.

Use it to answer: *"How many callers did we actually screen today, and how many reached a dentist?"*

### 18.2 DID journeys (`/calls/did`)

**Stats**

| Tile | Meaning |
|---|---|
| **Calls today** | Every arrival on the assigned DID. |
| **Reached an agent** | Pressed the agent option or reached an agent leg. |
| **Self-service** | No agent contact (recording or callback option). |
| **Live now** | Calls still in progress. |

**Filters** — From / To, **Menu option** (any recorded option, listed with its label), **Reached an agent** (*Either*, *Agent contact*, *Self-service only*), **Caller number**.

**The header also shows the assigned DID(s)** and the service window, so you can prove which line a call arrived on.

Each row shows the caller, the menu the caller heard, the **option they pressed** (or *Menu option not recorded*), and how the journey ended. This is the screen to open when someone claims they "kept pressing 3 and nobody answered".

### 18.3 All calls (`/live-operations/calls`)

The reconciled call list for deeper investigation.

**Filters**

- Scope — **All calls** or **My calls**.
- Connection — **Connected (verified)**, **Unverified bridge evidence**, **No connected dentist**.
- Date from / to.
- Agent.
- Dentist.
- State — **Active / missing hangup** or **Completed**.
- Caller number.

**Columns** — Call, Caller, Agent, Final verified dentist, Connection, Attempts, Answers, Transfer outcome, Duration, State, Flags.

**Export** — only shown if your account holds `export call history`. Standard agents normally do not, so plan exports through your supervisor. If the button is missing, that is the reason.

### 18.4 Choosing the right screen

| Question | Screen |
|---|---|
| "Did this caller reach us and what did they press?" | DID journeys |
| "How many did I screen today?" | Agent calls |
| "Which calls ended without a verified dentist?" | All calls (connection filter) |
| "What exactly happened on this one call?" | Open the call record (§19) |

---

## 19. Reading a call record

Clicking a call anywhere opens **Call #id**, with a description line showing the connection label and outcome. It is laid out in lettered sections:

| Section | Contents |
|---|---|
| **A. Caller & call** | Caller number (masked), inbound DID, start/answer/end times, status, timing quality, correlation key. |
| **B. Dentist & transfer** | Final verified dentist, transfer outcome, attempts summary, approved-number match. |
| **C. Questionnaire & answers** | The protocol version used, each question and recorded answer, answer statuses and question versions. |
| **D. Transfer attempts** | Every attempt with its status (`unanswered`, `busy`, `declined`, `accepted`, `completed`, …), timing and match status. |
| **E. Call timeline** | Ordered PBX/AMI events — ringing, answered, hold, dial, bridge, hangup — with timestamps. |
| **F. Durations** | Ringing, waiting, talk time, total duration. |
| **G. Notes & audit** | Factual notes, consent history, and the access/change audit trail. |
| **Evidence freshness** | Whether the call's evidence is live, stale, or complete, and any exceptions raised. |

How to read it:

- **Trust the timeline over memory.** If the timeline shows the dentist leg unanswered, the transfer did not happen, whatever the caller believes.
- **Missing hangup** shows as an active call with a long duration. Use the workspace ambiguity rule (§7.5) rather than trying to "fix" the record.
- **Access audit** records who opened and changed the case. Do not be alarmed by your own entries; they are required.

---

## 20. Dentist directory (read-only)

As an agent you can read every dentist screen but change **nothing**. Edit buttons appear only for accounts with `edit dentists`, so your views are safe to browse during a call.

Use the tab strip: **Directory · Numbers · Credentials · Rosters · Availability · Performance**.

### 20.1 Directory

- Summary tiles: **Rostered now**, **On break**, **On leave**, **Not rostered**.
- Search by dentist **name** or number; filter by coverage state and by active/inactive.
- Each row shows the dentist's contact numbers, coverage state, roster window and any manual availability override.
- **Select a rostered dentist** panel lists only dentists who are rostered **and** have an approved number — this is the same list used for transfers.

**Coverage states**

| State | Meaning |
|---|---|
| **rostered** | Inside a published shift window right now. |
| **on_break** | Rostered but on a recorded break. |
| **on_leave** | Approved leave covering now. |
| **unavailable** | A manual override says unavailable/busy. |
| **not_rostered** | Active dentist with no shift covering now. |
| **inactive** | Dentist is not active in the directory. |

Coverage is driven by **rosters and timestamped overrides**. Call activity is observed transfer evidence only — never personal-mobile presence.

### 20.2 Numbers

Approved / pending / retired counts, a filterable number list, and unmatched-attempt evidence. Only **approved** numbers can be used for transfers. Never dial a `pending` or `retired` number.

### 20.3 Credentials

Licence, registration and indemnity records. Validity is derived from the expiry date, so a lapsed credential is never shown as current. If you are asked "is Dr X licensed?", read the status and expiry — do not reassure from memory.

### 20.4 Rosters

- The **published service window** panel: *Monday to Friday · 10:00–14:00 Ghana time*, closed on Ghana public holidays, with the upcoming holiday chips.
- Shift table by dentist and date.

Agents cannot create, edit or generate rosters — those controls need the `edit dentists` permission (supervisors and administrators). If a roster looks wrong, report it — do not "work around" a missing shift by promising a caller a dentist who is not rostered.

### 20.5 Availability

Timestamped manual overrides layered over rosters. Availability is a **scheduling statement**, not evidence that a mobile is reachable. Only administrators record overrides.

### 20.6 Performance

Attempts, answered, failed, answer rate, average dial-to-answer and average ringing time over a selectable window. These are **observed EXP call attempts**, not a judgement about a person's availability. Use it to choose a realistic second choice when the first dentist does not answer — never to pressure a dentist.

---

## 21. Privacy, consent and evidence rules

These are not style preferences. Breaking them creates a reportable incident.

### 21.1 Privacy

1. **Minimum necessary.** Record only what the call requires. No full addresses, no ID numbers, no insurance policy numbers.
2. **Treat phone numbers as restricted data.** The case transfer panel shows masked numbers; the directory shows formatted numbers to accounts with directory access. Either way: do not read a number aloud to a caller, screenshot it, or paste it into chat — dial from the PBX.
3. **Confirm identity verbally.** The caller ID is evidence, not proof of identity. Say "I can see a number ending 4567 — is that the best number for you?"
4. **Consent before content.** No consent, no screening. If consent is declined or withdrawn, stop asking clinical questions.
5. **Callbacks need permission.** Tick the callback box only when the caller explicitly agrees. A callback request without permission cannot be closed as a callback.
6. **Notes are part of the health record.** Write facts, not speculation about the caller's character or truthfulness.
7. **Never share screens.** Screen-sharing during a live call exposes other callers' data. If you need help, quote the case number and correlation key instead.

### 21.2 Evidence

1. **The console never infers.** Where evidence is missing it says so: *option not recorded*, *unverified*, *no connected dentist*, *PBX stale*.
2. **Do not force a status.** If the dentist did not answer, the disposition is not "urgent referral completed". Record the truth and the next step.
3. **Every change is audited** with your user id, timestamp and revision. History cannot be edited or deleted; corrections are new entries.
4. **Revision conflicts are real.** "This case changed" means someone else saved first — reload and re-apply. Never try to save twice in a row to push through.
5. **Two windows, one case.** Editing the same case in two tabs causes draft conflicts. Work in one tab per case.
6. **Closed means closed.** The only change allowed on a closed case is withdrawal of consent. If a case must be reopened, that is a supervisor action.

### 21.3 What to report to your supervisor

When something goes wrong, give them this in one message:

- **when** it happened (Ghana time) and **which screen**;
- **case id** and **call/interaction id or correlation key**;
- **what you saw** (exact wording of any error, or the badge state: PBX stale/offline, service closed);
- **what you did** and the current call state (caller still on the line? transferred? ended?);
- a screenshot **only if** it contains no full phone numbers or clinical detail beyond what is necessary.

---

## 22. Troubleshooting

| Symptom | Likely cause | What to do |
|---|---|---|
| **No calls appear in the workspace** | PBX telemetry stale/offline, or polling paused | Check the PBX badge and heartbeat, press **Refresh calls**, check Jitsi/switchboard, resume prompts, tell your supervisor if it persists. |
| **"PBX stale/offline" warning** | AMI listener has not reported | Do not assume the queue is empty. Work from Jitsi; report it. |
| **Call controls say "unavailable"** | Gateway not configured, case not open, or you are not the assigned agent | Continue in Jitsi; that is the documented fallback. |
| **"An active live-call certification is required…"** | Certification missing or expired | Document existing cases only; ask your supervisor to renew it. |
| **"This case changed. Reload it…"** | Another operator saved first | Reload the case, re-read current values, re-apply your change once. |
| **"These answers were saved in another window…"** | Draft edited in two tabs | Click **Discard this window's unsaved answers and reload the case**. |
| **Form fields are greyed out** | Consent not granted, or the case is closed | Record consent first; closed cases cannot be edited except withdrawal. |
| **403 page / "This action is unauthorized."** | Missing permission for that button | Stop; ask your supervisor. Do not retry repeatedly. |
| **Session expired / 419 page** | You were signed out (idle or reset) | Sign in again; recover the draft from the safety screen and verify it against the call. |
| **Export button missing on All calls** | Your role lacks `export call history` | Ask your supervisor to run the export. |
| **Something looks wrong with the service window** | A holiday may be missing or a record changed | Report it with the date and time; never edit service hours yourself. |
| **Call shows "unverified bridge evidence"** | Bridge exists but the answer cannot be matched to an approved number | Record the next step truthfully, note it, and tell your supervisor. |
| **Call stays "active" for hours** | Possible missing hang-up | Do not open a new case from it; use the ambiguity acknowledgement only after confirming with your supervisor. |
| **Dentist list is empty for a P1** | Intentional — emergencies never wait for a dentist | Give emergency instructions and notify your supervisor. |

### 22.1 A five-step recovery routine

1. **Look at the badge** (PBX and service window) before touching anything.
2. **Refresh once** — `Refresh calls` or the browser reload.
3. **Check Jitsi** — the phone is always the source of truth for the live call.
4. **Tell the caller something true** — "I'm having trouble with my screen; I'm still here with you."
5. **Escalate** with the details from §21.3 if the problem persists past one call.

---

## 23. Shift checklist

### Before the window opens

- [ ] Signed in, 2FA approved, correct user shown in the sidebar.
- [ ] Jitsi connected, headset tested, extension correct.
- [ ] Dashboard shows the PBX connection healthy (or the issue is already reported).
- [ ] Service-window badge shows **Open · Mon–Fri 10:00–14:00** (or you know why it is closed).
- [ ] Workspace open, live prompts running, active-call panel empty or understood.
- [ ] Live-call certification current (otherwise you cannot open new cases).

### During a call

- [ ] Answer in Jitsi first; confirm the caller verbally.
- [ ] Match the right active call, tick association, acknowledge ambiguity only when justified.
- [ ] Record consent **before** anything else; callback permission only if given.
- [ ] Capture caller details; choose contact type and concern type honestly.
- [ ] Ask every safety question in order; *Unknown* rather than guessing.
- [ ] Record the safety screen — not just a draft.
- [ ] Copy the SBAR handover; read the priority guidance.
- [ ] Transfer only to an approved, available number; brief privately; wait for human acceptance.
- [ ] Record the disposition that matches what actually happened, with a next step.
- [ ] Add a factual note for anything unusual.

### Before you leave

- [ ] Every case you own has an owner, a next action and a due time.
- [ ] Handover recorded and acknowledged by the incoming owner.
- [ ] No drafts left unrecorded — record the screen or note it in the handover.
- [ ] Nothing pending in call controls (no stuck command).
- [ ] Signed out of the console and Jitsi.

---

## 24. Glossary

| Term | Meaning |
|---|---|
| **Agent leg** | The PBX channel that connects the caller to an agent. Its observed creation is required before a case can be opened. |
| **AMI** | Asterisk Manager Interface — the read-only telemetry feed the console uses to observe calls. |
| **Bridge** | The point where caller and dentist are connected. "Verified bridge" means the answer matched an approved number. |
| **Callback** | A request to call the caller back later. Requires a confirmed number and explicit permission. |
| **Case** | The record of one caller contact: consent, details, screening, handover, disposition, notes. |
| **Clinical review** | A case status (`clinical_review`) meaning a clinician/reviewer must decide. Not an error. |
| **Correlation key** | The identifier that ties PBX events to one interaction. Quote it when reporting problems. |
| **Coverage state** | Whether a dentist is rostered/on break/on leave/unavailable/not rostered/inactive right now. |
| **DID** | Direct Inward Dialling — the public number callers reach. |
| **Disposition** | The recorded outcome of a case, e.g. urgent referral, callback requested, FAQ resolved. |
| **Draft** | Autosaved but unconfirmed questionnaire answers. Never counts as a screening. |
| **EXP** | The operations programme this console supports; "verified EXP" means the call is confirmed as an EXP journey. |
| **IVR** | The recorded menu callers hear (1 recording, 2 callback, 3 agent). |
| **Interaction** | One call journey in the PBX/CDR evidence (`Call #id`). |
| **Jitsi** | The telephony console used to answer, hold, dial and hang up. Always the manual fallback. |
| **Masked number** | A partially hidden phone number (e.g. `233*****34`) shown instead of the full value. |
| **Priority (P1/P2/P3/S/Review)** | The safety classification that drives handover and allowed dispositions. |
| **Roster** | A published duty shift (10:00–14:00 on service days). |
| **SBAR** | Situation, Background, Assessment information, Request — the handover format. |
| **Screening** | The recorded, versioned safety questionnaire. |
| **Service window** | Mon–Fri 10:00–14:00 Ghana time, excluding Ghana public holidays. |
| **Transfer attempt** | One dial to a dentist number, with a status such as unanswered, busy, declined, accepted. |
| **Unverified** | Evidence exists but cannot be proven (for example a bridge without an approved-number match). |

---

## 25. Quick reference card

**Ten rules that keep you and the caller safe**

1. **Jitsi first, EXP second.** The phone makes the call; the console records it.
2. **One call, one case.** Confirm the association before typing anything.
3. **No consent, no screening.**
4. **Ask every question in order.** *Unknown* is an answer; a guess is not.
5. **Emergencies do not wait.** P1 = approved instructions given + supervisor notified.
6. **Never claim a transfer that did not happen.** No answer means no answer.
7. **Availability is a schedule, not a promise.**
8. **Numbers stay masked.** Dial from the PBX; write only the last four digits.
9. **A draft is not a screening.** Record it before you close.
10. **When the badge says stale, believe it.** Check the phone and escalate.

**Fast paths**

| I need to… | Go to |
|---|---|
| See today's calls and answer one | **Agent Workspace** (`/workspace`) |
| Check what a caller pressed | **Calls → DID journeys** (`/calls/did`) |
| Review my screened calls | **Calls → Agent calls** (`/calls/agent`) |
| Investigate one call end to end | **Calls → All calls** → open the call |
| Find a rostered dentist | **Dentists → Directory** or the case's **Transfer directory** |
| Check the service window or holidays | **Dentists → Rosters** |
| End my shift cleanly | `/cases/assignments` → `/cases/handover` |

**Escalate immediately when:** a P1 emergency occurs, the PBX is stale/offline for more than a few minutes, a case is visible to the wrong person, a consent withdrawal is disputed, or a caller reports harm after a transfer.

---

*End of manual. Keep a printed copy of §25 at your desk.*
