---
name: sprint1-voice-google-tasks
description: Connect native Google Tasks tools (list, create, complete, delete) to Jarvis Voice AI Agent in 01-voice-agent-core.json so the user can manage Google Tasks (@default) by voice.
---
# Sprint 1 (Patch) — Connect Google Tasks Tools to `01-voice-agent-core.json`

## 1. Objective
Give `Jarvis Voice AI Agent` in `workflows/01-voice-agent-core.json` full read/write/delete control over the user's main Google Tasks list (`@default`) using native n8n AI Tool nodes (`n8n-nodes-base.googleTasksTool`) and the existing sanitized credential `GTASKS_CREDENTIAL_ID` (`Jarvis Tasks`).

---

## 2. Nodes to Add to `workflows/01-voice-agent-core.json`
Add 4 `n8n-nodes-base.googleTasksTool` (`typeVersion: 1`) nodes, each connected via `ai_tool` to `Jarvis Voice AI Agent`, all using credential `googleTasksOAuth2Api` (`id: "GTASKS_CREDENTIAL_ID"`, `name: "Jarvis Tasks"`):

1. **`google_tasks_list`**
   - `descriptionType: "manual"`, `toolDescription`: "Liste les tâches actives dans Google Tasks (@default) avec leurs IDs et titres. Toujours appeler cet outil AVANT de compléter ou supprimer une tâche pour obtenir son taskId exact."
   - `resource: "task"`, `operation: "getAll"`, `task: "@default"`, `returnAll: false`, `limit: 50`, `additionalFields: { showCompleted: false }`.

2. **`google_tasks_create`**
   - `descriptionType: "manual"`, `toolDescription`: "Crée une nouvelle tâche dans Google Tasks (@default)."
   - `resource: "task"`, `operation: "create"`, `task: "@default"`, `title: "={{ $fromAI('title', 'Titre concis et actionnable de la tâche', 'string') }}"`, `additionalFields: { notes: "={{ $fromAI('notes', 'Notes ou contexte optionnel', 'string') }}" }`.

3. **`google_tasks_complete`**
   - `descriptionType: "manual"`, `toolDescription`: "Marque une tâche existante comme terminée (completed) dans Google Tasks (@default). Nécessite le taskId exact obtenu via google_tasks_list."
   - `resource: "task"`, `operation: "update"`, `task: "@default"`, `taskId: "={{ $fromAI('taskId', 'ID exact de la tâche Google Tasks', 'string') }}"`, `updateFields: { status: "completed" }`.

4. **`google_tasks_delete`**
   - `descriptionType: "manual"`, `toolDescription`: "Supprime définitivement une tâche dans Google Tasks (@default). Nécessite le taskId exact obtenu via google_tasks_list."
   - `resource: "task"`, `operation: "delete"`, `task: "@default"`, `taskId: "={{ $fromAI('taskId', 'ID exact de la tâche Google Tasks', 'string') }}"`.

---

## 3. Agent & Prompt Updates in `workflows/01-voice-agent-core.json`
- Increase `maxIterations` on `Jarvis Voice AI Agent` from `4` to `6` (to allow `google_tasks_list` + `google_tasks_delete`/`complete` + tracker tools in one turn).
- Update `systemMessage` to document the 4 Google Tasks tools and enforce this strict rule:
  - **Règle Google Tasks :** Pour supprimer, cocher ou vérifier une tâche externe, appelle OBLIGATOIREMENT `google_tasks_list` en premier pour récupérer son `taskId` exact, puis appelle `google_tasks_delete` ou `google_tasks_complete` avec cet ID. Ne devine jamais un `taskId`.

---

## 4. Acceptance Criteria
1. Update `workflows/01-voice-agent-core.json` (keep `models/gemini-3.5-flash-lite` and `$env.DISCORD_BRIEFING_WEBHOOK_URL`).
2. Run `.agents/hooks/pre-commit-secrets.sh` (valid JSON, 0 hardcoded secrets).
3. Output the standard `IMPLEMENTATION REPORT`.