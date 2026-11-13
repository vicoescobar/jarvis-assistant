---
name: sprint1-morning-briefing-discord
description: Implement Step 1.2 — Private Discord Notification Channel & Adaptive/Negotiable Morning Briefing workflow (02-morning-briefing.json) + Cycle Shift fix.
---
# Sprint 1 (Step 1.2) — Discord Channel & Adaptive Morning Briefing

## 1. Objectives
1. **Fix `skip_or_shift_cycle` delta in `workflows/01-voice-agent-core.json`**: Ensure skipping/postponing a workout keeps `current_index` on today's session (`defaultDelta = 0`) so it is re-proposed tomorrow without guilt, and ensure `modelName` is locked to `models/gemini-3.5-flash-lite` with `maxIterations: 3`.
2. **Discord Outbound Integration**: Pass `DISCORD_BRIEFING_WEBHOOK_URL` from `infra/.env` into `jarvis-n8n` via `infra/docker-compose.yml` (`N8N_BLOCK_ENV_ACCESS_IN_NODE=false`) so workflows can send rich Discord messages without hardcoding webhook secrets in Git.
3. **Create `workflows/02-morning-briefing.json`**: Build the Adaptive Morning Briefing workflow triggered both by a daily Schedule (08:30 Europe/Paris) and an on-demand Webhook (`POST /webhook/morning-briefing`).
4. **Negotiation & Voice Feedback Loop**: Allow `01-voice-agent-core.json` to optionally forward its reply to Discord if `DISCORD_BRIEFING_WEBHOOK_URL` is configured, and support negotiating the morning activity suggestion.

---

## 2. Infrastructure & Configuration Updates

### A. Update `infra/docker-compose.yml` & `infra/.env.exemple`
1. In `infra/docker-compose.yml`, add to `environment:`:
   - `NODE_FUNCTION_ALLOW_BUILTIN=fs,path,crypto,https,url`
   - `N8N_BLOCK_ENV_ACCESS_IN_NODE=false`
   - `DISCORD_BRIEFING_WEBHOOK_URL=${DISCORD_BRIEFING_WEBHOOK_URL:-}`
2. In `infra/.env.exemple`, document:
   - `DISCORD_BRIEFING_WEBHOOK_URL=https://discord.com/api/webhooks/xxx/yyy`

### B. Patch `workflows/01-voice-agent-core.json`
1. Confirm the LLM node uses `"modelName": "models/gemini-3.5-flash-lite"`.
2. In `mutate_tracker_record` tool code, set:
   `const defaultDelta = op === 'skip_or_shift_cycle' ? 0 : 1;`
   `const delta = typeof payload.step_delta === 'number' ? payload.step_delta : defaultDelta;`
   `nextIndex = ((prevIndex + delta) % steps.length + steps.length) % steps.length;`
3. In the final response formatting (or a non-blocking post-processing code/HTTP step), if `$env.DISCORD_BRIEFING_WEBHOOK_URL` is non-empty and the request payload includes `"notify_discord": true` (or by default for voice commands), send a clean confirmation embed/message to Discord in addition to returning the HTTP JSON response.

---

## 3. Specification for `workflows/02-morning-briefing.json`

- **Workflow Deterministic ID:** `"id": "02-morning-briefing"`
- **Workflow Name:** `"02 - Adaptive Morning Briefing (Discord & Dynamic Trackers)"`

### Nodes Architecture:
1. **Triggers (Dual Entry):**
   - `Schedule Trigger`: Daily at `08:30` (`Europe/Paris` timezone).
   - `Webhook Trigger`: `POST /webhook/morning-briefing` (`responseMode: "responseNode"`).
2. **Code Node (`Gather Morning Context`):**
   - Reads `/home/node/user_data/trackers_registry.json` and `/home/node/user_data/memory_rules.json`.
   - Filters all trackers where `include_in_morning_briefing === true`:
     - For `CYCLE_STATE_MACHINE` (e.g., `cycle_musculation`): extracts `display_name`, current step (`steps[current_index]`), and `last_updated`.
     - For `COLLECTION_TABLE` (e.g., freelance/DJ CRM): reads `/home/node/user_data/data_<tracker_id>.json` and flags any record with status containing `quote` / `devis` / `pending` older than 10 days for proactive follow-up.
     - For `ACTIVITY_LOG`: reads recent entries (last 7 days) to help Gemini suggest a fresh activity.
   - Purges expired volatile rules in `memory_rules.json` (`expires_at < today`).
   - Reads optional `custom_focus` or `override_suggestion` passed in the Webhook body (if the user triggered a re-roll).
3. **AI Agent / LLM Chain (`Generate ADHD Morning Briefing` with `models/gemini-3.5-flash-lite`):**
   - **System Prompt Guardrails:**
     - Strictly max 5 short lines, warm & zero-guilt tone, French language.
     - Line 1 (Trackers State): State clearly today's cycle target (e.g. `🏋️ **Muscu :** Aujourd'hui c'est **Séance A** (ou dis-moi si on décale).`) + any pending CRM quote follow-up if detected.
     - Line 2 (Priority Plan Si/Alors): Formulate 1 concrete priority using Gollwitzer's Implementation Intention (`🎯 **Plan Si/Alors :** Si [Déclencheur], alors [Micro-action < 2 min]`).
     - Line 3 (Negotiable Activity Suggestion): Propose 1 rewarding activity based on `morning_rules` and variety (e.g., 45-min timed Ableton Sandbox ending with 15s MP3 export, DJ mix, or quick coding/admin task) and explicitly remind the user they can reply via voice to accept, change, or skip.
4. **Code / HTTP Dispatch Node (`Save Briefing State & Send to Discord`):**
   - Saves today's generated briefing summary and suggested activity into `memory_rules.json` under `custom_scopes.today_briefing_state` (with `volatile: true`, `expires_at: today`) so `01-voice-agent-core` knows what was proposed this morning when the user replies!
   - Sends the formatted message to `$env.DISCORD_BRIEFING_WEBHOOK_URL` via HTTP POST (if configured; gracefully logs a warning in JSON response if the env var is empty so testing via `curl` never crashes).
5. **Respond to Webhook Node:**
   - Returns `{ "status": "ok", "discord_sent": boolean, "briefing": "<generated_text>", "timestamp": "<ISO>" }`.

---

## 4. Acceptance Criteria & Handover
1. Update `AGENTS.md` / `README.md` if needed to reflect `02-morning-briefing.json`.
2. Run `.agents/hooks/pre-commit-secrets.sh` to verify valid JSON syntax and zero hardcoded webhook URLs or API keys.
3. Output the standard `IMPLEMENTATION REPORT` block for Loop 1 synchronization.

