---
name: sprint1-briefing-polish
description: Polish Step 1.3 — Give 02-morning-briefing.json generic tracker_mutations capability (prompt-driven rules, zero hardcoded tracker logic), switch Jarvis to a direct executive tone, remove Plan Si/Alors from the morning briefing, and surface all active Google Tasks (including undated ones).
---
# Sprint 1 (Step 1.3 Polish) — Prompt-Driven Morning Mutations, Executive Tone & Full Google Tasks

## 1. Updated Product & Tone Rules (Mandatory)
Update `.agents/skills/adhd-ux-guardrails/SKILL.md` and `AGENTS.md`:
- **Direct Executive Tone (No Patronizing Language):** ADHD accommodation lives in the *architecture* (noise filtering, prioritization, low friction, proactive orchestration), NEVER in soft, coddling, or infantilizing copy. Strictly ban phrases like "zéro pression", "au calme", "sans aucune culpabilité", "si l'énergie n'y est pas", "profite de cette liberté". Use a crisp, direct, factual chief-of-staff tone in French. Never downplay urgent items (bank/security alerts, deadlines, job leads).
- **No "Plan Si/Alors" in Morning Briefing:** Remove Gollwitzer's "Si/Alors" section completely from `02-morning-briefing.json`.
- **Surface All Active Google Tasks:** Undated tasks in `@default` are real tasks to do and MUST be displayed in the morning briefing.
- **Prompt-Driven Morning Capabilities (Schema-less):** Do NOT hardcode domain-specific flags (like `auto_advance_daily`) for specific trackers. Instead, give `02-morning-briefing.json` a generic `tracker_mutations` execution engine so any morning rule dictated by the user in `memory_rules.json` can mutate trackers automatically at runtime.

---

## 2. Update `workflows/02-morning-briefing.json`

### A. Expand the Strict JSON Output Schema in `Generate ADHD Morning Briefing`
Keep `models/gemini-3.5-flash-lite` (`GEMINI_CREDENTIAL_ID`) and instruct Gemini to return ONLY a valid JSON object (no markdown fences) with this exact schema:

    {
      "briefing_text": "Texte Markdown Discord bien aéré (5 sections courtes séparées par \\n\\n)",
      "tasks_to_create": [
        {
          "title": "Action concrète commençant par un verbe",
          "notes": "Contexte court + expéditeur/sujet",
          "due_date": "YYYY-MM-DD or null"
        }
      ],
      "tracker_mutations": [
        {
          "tracker_id": "string (snake_case from trackers_registry)",
          "operation": "advance_cycle | skip_or_shift_cycle | set_cycle_index | insert | update",
          "step_delta": 1,
          "target_index": null,
          "record_id": null,
          "record_data": {}
        }
      ]
    }

### B. Prompt Rules for `tracker_mutations`
- Instruct Gemini to read `morning_rules`, `custom_scopes`, and `cycle_trackers` (including each tracker's `last_updated` vs `current_date`).
- If a user rule in `morning_rules` (or `custom_scopes`) instructs Jarvis to advance a `CYCLE_STATE_MACHINE` each morning, update a status, or log an entry, Gemini MUST emit the corresponding object in `tracker_mutations` AND write `briefing_text` reflecting the **new post-mutation state** (e.g., if advancing from `Pull` to `Push`, announce `Push` in `briefing_text`).
- If a volatile rule in `custom_scopes` indicates the user skipped/paused the cycle yesterday or today, do NOT emit `advance_cycle` for that tracker.
- If no mutation is needed, return `"tracker_mutations": []`.

### C. Prompt Rules for `briefing_text` (Direct Executive Tone, 5 Airy Sections)
Separate each section with `\n\n`, no fluff, no patronizing phrases:
1. `📅 **Agenda**` : Liste factuelle des événements de `calendar_events` (horaire + titre), ou simplement `Aucun événement fixé.`
2. `📩 **Mails importants**` : Uniquement les mails nécessitant une action ou une vigilance (banque/sécurité, `stage`/`alternance`/`candidature`/`entretien`, règles `memory_rules`). Factuel et direct, sans minimiser l'urgence.
3. `✅ **Tâches en cours**` : Liste claire des tâches actives de `existing_tasks` (Google Tasks `@default`), **y compris celles sans date d'échéance** (jusqu'à 6 tâches clés). Si vide, indiquer `Aucune tâche en cours.`
4. `🏋️ **Trackers**` : État du jour des trackers actifs (en tenant compte des `tracker_mutations` appliquées ce matin) + éventuelles relances `overdue_follow_ups` > 10 jours. Strictement factuel.
5. `⚡ **Suggestion**` : 1 proposition concrète basée sur `morning_rules`, `existing_tasks` ou `recent_activities_7d`, en 1 phrase directe.

### D. Execute `tracker_mutations` Safely in `Parse Briefing & Tasks` Code Node
Before dispatching to Discord:
1. Parse `briefing_text`, `tasks_to_create`, and `tracker_mutations` (safely stripping any markdown fences, with clean fallback if parsing fails).
2. Execute each item in `tracker_mutations` against `/home/node/user_data/trackers_registry.json` and `/home/node/user_data/data_<tracker_id>.json` using the exact same generic logic as `mutate_tracker_record` in `01-voice-agent-core.json`:
   - **Idempotency Safeguard (Mandatory):** For `advance_cycle` (when `step_delta !== 0`), if `tracker.cycle_config.last_updated === today`, **skip** the mutation so triggering `/webhook/morning-briefing` multiple times on the same day never double-advances a cycle.
   - Persist updated `trackers_registry.json` and append the log entry to `data_<tracker_id>.json` with `id`, `created_at`, `updated_at`.
3. Keep saving `today_briefing_state` in `memory_rules.json` (`volatile: true`, `expires_at: today`) and dispatching `briefing_text` to Discord via `$env.DISCORD_BRIEFING_WEBHOOK_URL` (NEVER `process.env`).
4. Include `mutations_applied_count` and `mutations_applied` in the output JSON and in `Format Webhook Response`.

---

## 3. Update `workflows/01-voice-agent-core.json`
- Update the system prompt of `Jarvis Voice AI Agent` to match the new **direct executive tone** (remove patronizing phrases and remove mandatory "Si/Alors" phrasing; keep responses crisp, max 4 lines, action-oriented).
- Ensure that when the user dictates a recurring morning behavior (e.g., *"avance mon cycle chaque matin"* or *"inclus mon cycle dans le briefing du matin"*), the agent saves the behavioral instruction cleanly into `morning_rules` via `update_memory_rule` alongside creating/updating the tracker schema.
- When the user says they skipped a session today (e.g., *"aujourd'hui je n'y suis pas allé"*), instruct the agent to BOTH call `mutate_tracker_record` (`skip_or_shift_cycle`, `delta = 0`) AND add a short volatile rule in `custom_scopes` expiring tomorrow so the next morning briefing knows not to advance past the skipped session.

---

## 4. Acceptance Criteria & Handover
1. Update `workflows/02-morning-briefing.json`, `workflows/01-voice-agent-core.json`, `.agents/skills/adhd-ux-guardrails/SKILL.md`, and `AGENTS.md`.
2. Run `.agents/hooks/pre-commit-secrets.sh` to validate JSON syntax and confirm 0 hardcoded secrets.
3. Output the standard `IMPLEMENTATION REPORT` block for Loop 1 synchronization.