---
name: sprint1-voice-full-workspace
description: Expand 01-voice-agent-core.json with full Google Workspace native AI tools (Calendar, Gmail, Drive, Docs, Sheets) alongside existing Google Tasks and local Meta-Tools.
---
# Sprint 1 (Patch) — Full Google Workspace Tools in `01-voice-agent-core.json`

## 1. Objective
Give `Jarvis Voice AI Agent` in `workflows/01-voice-agent-core.json` complete voice-driven access to the user's Google Workspace (Calendar, Gmail, Drive, Docs, Sheets, and already-wired Tasks) using native n8n AI Tool nodes (`n8n-nodes-base.*Tool`) with `$fromAI(...)` parameters and sanitized credential placeholders.

---

## 2. Native AI Tool Nodes to Add (All wired via `ai_tool` to `Jarvis Voice AI Agent`)

Keep the 4 existing local Meta-Tools (`create_or_update_tracker_schema`, `mutate_tracker_record`, `query_tracker_records`, `update_memory_rule`) and the 4 existing `google_tasks_*` tools untouched. Add the following 13 nodes arranged cleanly in rows on the canvas:

### A. Google Calendar (`n8n-nodes-base.googleCalendarTool`, credential `googleCalendarOAuth2Api` -> `id: "GCAL_CREDENTIAL_ID"`, `name: "Jarvis Calendar"`)
1. **`google_calendar_list`**
   - `descriptionType: "manual"`, `toolDescription`: "Liste les événements Google Calendar (calendrier primary) entre timeMin et timeMax (format ISO-8601 avec fuseau, ex: 2026-11-18T00:00:00+01:00). Appeler avant toute suppression pour obtenir l'eventId exact."
   - `resource: "event"`, `operation: "getAll"`, `calendar: "primary"`, `returnAll: false`, `limit: 25`, `options: { singleEvents: true, timeMin: "={{ $fromAI('timeMin', 'Date/heure ISO début', 'string') }}", timeMax: "={{ $fromAI('timeMax', 'Date/heure ISO fin', 'string') }}" }`.
2. **`google_calendar_create`**
   - `descriptionType: "manual"`, `toolDescription`: "Crée un nouvel événement dans Google Calendar (primary)."
   - `resource: "event"`, `operation: "create"`, `calendar: "primary"`, `start: "={{ $fromAI('start', 'Date/heure ISO début', 'string') }}"`, `end: "={{ $fromAI('end', 'Date/heure ISO fin', 'string') }}"`, `additionalFields: { summary: "={{ $fromAI('summary', 'Titre de l événement', 'string') }}", description: "={{ $fromAI('description', 'Description optionnelle', 'string') }}" }`.
3. **`google_calendar_delete`**
   - `descriptionType: "manual"`, `toolDescription`: "Supprime un événement Google Calendar par son eventId exact obtenu via google_calendar_list."
   - `resource: "event"`, `operation: "delete"`, `calendar: "primary"`, `eventId: "={{ $fromAI('eventId', 'ID exact de l événement', 'string') }}"`.

### B. Gmail (`n8n-nodes-base.gmailTool`, credential `gmailOAuth2` -> `id: "GMAIL_CREDENTIAL_ID"`, `name: "Jarvis Gmail"`)
4. **`gmail_search`**
   - `descriptionType: "manual"`, `toolDescription`: "Recherche des emails dans Gmail avec une requête de filtrage (ex: 'from:revolut', 'stage OR alternance', 'newer_than:7d'). Renvoie les IDs, sujets et extraits."
   - `resource: "message"`, `operation: "getAll"`, `returnAll: false`, `limit: 10`, `filters: { q: "={{ $fromAI('query', 'Requête de recherche Gmail', 'string') }}" }`.
5. **`gmail_get`**
   - `descriptionType: "manual"`, `toolDescription`: "Lit le contenu complet d'un email Gmail à partir de son messageId obtenu via gmail_search."
   - `resource: "message"`, `operation: "get"`, `messageId: "={{ $fromAI('messageId', 'ID exact du message Gmail', 'string') }}"`.
6. **`gmail_create_draft`**
   - `descriptionType: "manual"`, `toolDescription`: "Crée un brouillon de réponse ou de nouvel email dans Gmail sans l'envoyer automatiquement."
   - `resource: "draft"`, `operation: "create"`, `subject: "={{ $fromAI('subject', 'Objet du mail', 'string') }}"`, `message: "={{ $fromAI('message', 'Corps du mail en texte clair', 'string') }}"`, `options: { sendTo: "={{ $fromAI('to', 'Adresse email du destinataire', 'string') }}" }`.

### C. Google Drive / Cloud (`n8n-nodes-base.googleDriveTool`, credential `googleDriveOAuth2Api` -> `id: "GDRIVE_CREDENTIAL_ID"`, `name: "Jarvis Drive"`)
7. **`google_drive_search`**
   - `descriptionType: "manual"`, `toolDescription`: "Recherche des fichiers (Google Docs, Sheets, PDF, dossiers) dans Google Drive par nom ou mot-clé. Toujours appeler en premier pour récupérer l'ID exact d'un Doc ou d'un Sheet."
   - `resource: "fileFolder"`, `operation: "search"`, `returnAll: false`, `limit: 15`, `queryString: "={{ $fromAI('query', 'Nom ou mot-clé du fichier à chercher', 'string') }}"`.
8. **`google_drive_create_file`**
   - `descriptionType: "manual"`, `toolDescription`: "Crée un fichier texte/Markdown dans Google Drive (utile pour la Boîte à vrac ou des notes rapides)."
   - `resource: "file"`, `operation: "createFromText"`, `name: "={{ $fromAI('name', 'Nom du fichier ex: note.md', 'string') }}"`, `content: "={{ $fromAI('content', 'Contenu texte du fichier', 'string') }}"`.

### D. Google Docs (`n8n-nodes-base.googleDocsTool`, credential `googleDocsOAuth2Api` -> `id: "GDOCS_CREDENTIAL_ID"`, `name: "Jarvis Docs"`)
9. **`google_docs_get`**
   - `descriptionType: "manual"`, `toolDescription`: "Lit le contenu d'un Google Doc à partir de son documentId (obtenu via google_drive_search)."
   - `resource: "document"`, `operation: "get"`, `documentURL: "={{ $fromAI('documentId', 'ID exact du Google Doc', 'string') }}"`.
10. **`google_docs_create`**
    - `descriptionType: "manual"`, `toolDescription`: "Crée un nouveau Google Doc vide avec un titre."
    - `resource: "document"`, `operation: "create"`, `title: "={{ $fromAI('title', 'Titre du nouveau Google Doc', 'string') }}"`.
11. **`google_docs_append`**
    - `descriptionType: "manual"`, `toolDescription`: "Ajoute du texte à la fin d'un Google Doc existant (nécessite le documentId obtenu via google_drive_search)."
    - `resource: "document"`, `operation: "update"`, `documentURL: "={{ $fromAI('documentId', 'ID exact du Google Doc', 'string') }}"`, `actionsUi: { actionFields: [{ action: "insert", locationChoice: "location", insertSegment: "body", text: "={{ $fromAI('text', 'Texte à insérer dans le document', 'string') }}" }] }`.

### E. Google Sheets (`n8n-nodes-base.googleSheetsTool`, credential `googleSheetsOAuth2Api` -> `id: "GSHEETS_CREDENTIAL_ID"`, `name: "Jarvis Sheets"`)
12. **`google_sheets_read`**
    - `descriptionType: "manual"`, `toolDescription`: "Lit les lignes d'un onglet Google Sheets (nécessite documentId obtenu via google_drive_search et sheetName)."
    - `resource: "sheet"`, `operation: "read"`, `documentId: "={{ $fromAI('documentId', 'ID exact du Google Sheet', 'string') }}"`, `sheetName: "={{ $fromAI('sheetName', 'Nom exact de l onglet ex: Feuille 1', 'string') }}"`.
13. **`google_sheets_append`**
    - `descriptionType: "manual"`, `toolDescription`: "Ajoute une nouvelle ligne à un Google Sheet existant (nécessite documentId et sheetName)."
    - `resource: "sheet"`, `operation: "append"`, `documentId: "={{ $fromAI('documentId', 'ID exact du Google Sheet', 'string') }}"`, `sheetName: "={{ $fromAI('sheetName', 'Nom exact de l onglet', 'string') }}"`, `columns: { mappingMode: "autoMapInputData" }`.

---

## 3. Agent & Prompt Updates in `workflows/01-voice-agent-core.json`
- Increase `maxIterations` on `Jarvis Voice AI Agent` from `6` to `8` (enables chained calls like `google_drive_search` -> `google_docs_get` -> `google_docs_append`).
- Keep `models/gemini-3.5-flash-lite` and `$env.DISCORD_BRIEFING_WEBHOOK_URL`.
- Update `systemMessage` with clear routing rules:
  - **Résolution d'ID obligatoire avant action :** Ne jamais deviner un `taskId`, `eventId`, `messageId` ou `documentId`. Toujours appeler `google_tasks_list`, `google_calendar_list`, `gmail_search` ou `google_drive_search` en premier.
  - **Sécurité Gmail :** Utiliser `gmail_create_draft` pour préparer un mail et informer l'utilisateur que le brouillon est prêt dans Gmail.
  - **Séparation Trackers locaux vs Google Workspace :** Utiliser les 4 méta-outils locaux pour les trackers dynamiques (`cycle_musculation`, CRM, règles mémoire) et les outils Google Workspace dès que l'utilisateur mentionne explicitement son agenda, ses mails, ses tâches, son Drive, un Doc ou un Sheet.

---

## 4. Acceptance Criteria
1. Update `workflows/01-voice-agent-core.json`, `AGENTS.md`, and `README.md`.
2. Run `.agents/hooks/pre-commit-secrets.sh` (valid JSON, 0 hardcoded secrets or real credential IDs).
3. Output the standard `IMPLEMENTATION REPORT`.