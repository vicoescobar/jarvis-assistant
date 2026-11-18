---
name: sprint1-google-workspace-memory
description: Implement Step 1.3 — Enrich 02-morning-briefing.json with Google Workspace (Gmail, Calendar, Tasks) and create 03-memory-compression.json (monthly 30-day log compression). Remove Night Amnesty.
---
# Sprint 1 (Step 1.3) — Google Workspace Sync & Long-Term Memory Compression

## 1. Objectives & Updated Product Rules
1. **No Night Amnesty (Updated User Rule):** Do NOT implement any automated task rollover/moving/deletion on Google Tasks. All unfinished tasks remain untouched in the user's main Google Tasks list (`@default`). Update `AGENTS.md` and `.agents/skills/adhd-ux-guardrails/SKILL.md` accordingly.
2. **Enrich `workflows/02-morning-briefing.json` with Google Workspace:**
   - Read today's events from **Google Calendar** (`Jarvis Calendar`).
   - Read recent emails (last 24h) from **Gmail** (`Jarvis Gmail`) and apply dynamic filtering rules from `memory_rules.json` (plus internship/job search priority: `stage`, `alternance`, `candidature`, `entretien`).
   - Read current incomplete tasks from **Google Tasks** (`Jarvis Tasks`, main list `@default`) to avoid duplicates and give Gemini accurate daily context without shaming.
   - Automatically create actionable tasks in **Google Tasks** (`@default`) when Gemini detects an urgent email requiring a reply or action.
3. **Create `workflows/03-memory-compression.json` (Inspired by OpenJarvis `monitor_operative`):**
   - Monthly maintenance job (1st of each month at `03:00` `Europe/Paris`) + on-demand test Webhook (`POST /webhook/compress-memory`).
   - Summarizes tracker records older than 30 days in `/home/node/user_data/data_<tracker_id>.json` into compact monthly digests in `/home/node/user_data/archive_<tracker_id>.json` so active JSON files never bloat Gemini's context window.

---

## 2. Technical Constraints (Mandatory)
- **Model:** Lock all LLM nodes to `models/gemini-3.5-flash-lite`.
- **n8n Sandbox (`JsTaskRunnerSandbox`):** NEVER use `process.env`. Always read environment variables safely via:
  `const webhookUrl = (typeof $env !== 'undefined' && $env.DISCORD_BRIEFING_WEBHOOK_URL ? $env.DISCORD_BRIEFING_WEBHOOK_URL : '').trim();`
- **Credentials Sanitization (Public Repo):** Never hardcode real n8n credential IDs, OAuth tokens, or personal emails in `workflows/*.json`. Use sanitized placeholder IDs matching the user's n8n credential names:
  - `GEMINI_CREDENTIAL_ID` (`Google Gemini API`)
  - `GMAIL_CREDENTIAL_ID` (`Jarvis Gmail`)
  - `GCAL_CREDENTIAL_ID` (`Jarvis Calendar`)
  - `GTASKS_CREDENTIAL_ID` (`Jarvis Tasks`)
- **Graceful Degradation:** On Google Workspace read nodes (Calendar, Gmail, Tasks), enable `continueOnFail: true` or `alwaysOutputData: true` so an empty inbox or an empty calendar day never stops the morning briefing pipeline.

---

## 3. Specification — Enriching `workflows/02-morning-briefing.json`

Keep deterministic `"id": "02-morning-briefing"` and the dual triggers (`Daily 08:30 Trigger` + `POST /webhook/morning-briefing`).

### Data Gathering Phase (Before Gemini):
1. **Fetch Today's Calendar Events (`n8n-nodes-base.googleCalendar`):**
   - Operation: `getAll` on `primary` calendar, `singleEvents: true`, `timeMin` = start of today (`Europe/Paris`), `timeMax` = end of today (`Europe/Paris`).
   - Extract only compact fields: `summary`, `start`, `end`, `location`.
2. **Fetch Recent Gmail Messages (`n8n-nodes-base.gmail`):**
   - Operation: `getAll`, query: `newer_than:1d -category:promotions -category:social`, limit: `15`.
   - Extract only compact fields: `id`, `from`, `subject`, `snippet`, `date`.
3. **Fetch Active Google Tasks (`n8n-nodes-base.googleTasks`):**
   - Operation: `getAll` on `@default` list (`showCompleted: false`, limit: `20`).
   - Extract `id`, `title`, `due`.
4. **Merge & Context Assembly (`Gather Morning Context` Code Node):**
   - Combines local `/home/node/user_data/` (`trackers_registry.json`, `memory_rules.json`, overdue `COLLECTION_TABLE` items > 10 days, recent `ACTIVITY_LOG` entries) WITH the fetched `calendar_events`, `recent_emails`, and `existing_tasks`.
   - Still purges expired volatile rules (`expires_at < today`).

### AI Generation & Action Extraction (`Generate ADHD Morning Briefing`):
- Instruct `models/gemini-3.5-flash-lite` to return a **strict JSON object** (no markdown fences) with this exact schema:

    {
      "briefing_text": "Max 5 short lines in French, warm & zero-guilt tone: (1) Agenda du jour if any, (2) Mails importants / stage filtered via memory_rules, (3) Trackers actifs (ex: cycle_musculation + relances devis > 10j), (4) 1 Plan Si/Alors (< 2 min), (5) 1 activité négociable.",
      "tasks_to_create": [
        {
          "title": "Actionable task starting with a physical verb (e.g., 'Répondre au mail de X pour le stage')",
          "notes": "Contexte court + expéditeur/sujet du mail",
          "due_date": "YYYY-MM-DD or null"
        }
      ]
    }

- **Deduplication Rule:** Gemini must NOT propose a task in `tasks_to_create` if a similar task already exists in `existing_tasks`.

### Post-Processing & Dispatch:
1. **Parse JSON Output (`Parse Briefing & Tasks` Code Node):** Safely strips any accidental markdown code fences, parses JSON (with fallback to raw text as `briefing_text` if parsing fails), saves `today_briefing_state` in `memory_rules.json` (`volatile: true`, `expires_at: today`), and dispatches `briefing_text` to Discord via `$env.DISCORD_BRIEFING_WEBHOOK_URL`.
2. **Create Extracted Tasks (`n8n-nodes-base.googleTasks`):** If `tasks_to_create` is non-empty, loop/create each item in the `@default` Google Tasks list.
3. **Respond to Webhook:** Return `{ status: "ok", discord_sent, tasks_created_count, briefing, timestamp }`.

---

## 4. Specification — Creating `workflows/03-memory-compression.json`

- **Workflow Deterministic ID:** `"id": "03-memory-compression"`
- **Workflow Name:** `"03 - Long-Term Memory Compression (Monthly Log Archiver)"`

### Nodes Architecture:
1. **Triggers (Dual Entry):**
   - `Schedule Trigger`: 1st of every month at `03:00` (`Europe/Paris`, cron `0 3 1 * *`).
   - `Webhook Trigger`: `POST /webhook/compress-memory` (`responseMode: "responseNode"`).
2. **Code Node (`Scan Stale Tracker Records`):**
   - Purges expired volatile rules in `/home/node/user_data/memory_rules.json`.
   - Reads `trackers_registry.json` and inspects every `data_<tracker_id>.json`.
   - Splits records into `recent_records` (<= 30 days) and `stale_records` (> 30 days, based on `created_at` / `updated_at`).
   - Skips `COLLECTION_TABLE` records whose status is still active/pending (never archive open CRM quotes/leads — only archive closed/completed records older than 30 days, or all `ACTIVITY_LOG` / `CYCLE_STATE_MACHINE` logs older than 30 days).
   - Emits one item per tracker having `stale_records.length >= 5` (or `>= 1` if `force: true` is passed to the webhook). If no tracker needs compression, routes cleanly to the final Webhook response with `{ status: "ok", compressed_trackers: 0 }`.
3. **LLM Node (`Summarize Stale Records` — `models/gemini-3.5-flash-lite`):**
   - Compresses the `stale_records` array into a compact JSON digest:
     `{ "period_start": "YYYY-MM-DD", "period_end": "YYYY-MM-DD", "record_count": number, "highlights": ["max 3 concise facts/metrics"], "summary": "2-sentence compact summary" }`
4. **Code Node (`Write Archive & Trim Active File`):**
   - Appends the digest to `/home/node/user_data/archive_<tracker_id>.json` (creating it if needed).
   - Overwrites `/home/node/user_data/data_<tracker_id>.json` with only `recent_records` (keeping active files lean).
5. **Respond to Webhook:**
   - Returns `{ status: "ok", compressed_trackers: [...], purged_rules_count: number, timestamp }`.

---

## 5. Acceptance Criteria & Handover
1. Update `workflows/02-morning-briefing.json` and create `workflows/03-memory-compression.json`.
2. Update `AGENTS.md`, `README.md`, and `.agents/skills/adhd-ux-guardrails/SKILL.md` to reflect:
   - Unified Google Workspace integration (Gmail, Calendar, Tasks, Drive, Docs, Sheets).
   - Removal of Night Amnesty (tasks stay in main Google Tasks list).
   - Addition of `03-memory-compression.json`.
3. Run `.agents/hooks/pre-commit-secrets.sh` to validate JSON syntax and confirm 0 hardcoded secrets.
4. Output the standard `IMPLEMENTATION REPORT` block for Loop 1 synchronization.