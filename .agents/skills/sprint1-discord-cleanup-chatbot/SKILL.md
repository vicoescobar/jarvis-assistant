---
name: sprint1-discord-cleanup-chatbot
description: Close Sprint 1 — Route voice command logs to a dedicated Discord channel (#logs-voix) and implement a persistent Discord chatbot (#chatbot) supporting text and audio attachments with conversation history.
---
# Sprint 1 (Closure) — Discord Channel Separation & Persistent Chatbot (Text + Audio)

## 1. Objectives
1. **Separate Discord Channels (`#briefing` vs `#logs-voix`):** Stop `01-voice-agent-core.json` from posting voice command logs into the Morning Briefing channel (`DISCORD_BRIEFING_WEBHOOK_URL`). Route them to a dedicated `DISCORD_VOICE_LOG_WEBHOOK_URL` (`#logs-voix`).
2. **Persistent Discord Chatbot (`#chatbot`):** Allow the user to chat with Jarvis in a dedicated Discord channel via **text messages** OR **audio attachments** (Discord voice notes `.ogg`, `.mp3`, `.wav`, `.m4a`) with **persistent multi-turn conversation memory** stored in `/home/node/user_data/discord_chat_history.json`.
3. **Executive Tone & Full Context:** The chatbot uses `models/gemini-3.5-flash-lite`, adheres strictly to the direct executive tone (`.agents/skills/adhd-ux-guardrails/SKILL.md`), reads `trackers_registry.json` + `memory_rules.json`, and can delegate actions (trackers / Google Workspace) to `01-voice-agent-core` when the user asks to perform an action.
4. **Backlog Note:** Record "Real-time Discord Voice Channel (RTC)" as a future bonus item in `AGENTS.md` / `README.md` (not to be implemented now).

---

## 2. Infrastructure & Environment Updates

### A. Update `infra/.env.exemple`
Add the following sanitized placeholders (compatible with `.agents/hooks/pre-commit-secrets.sh`):

    DISCORD_BRIEFING_WEBHOOK_URL=https://discord.com/api/webhooks/xxx/yyy
    DISCORD_VOICE_LOG_WEBHOOK_URL=https://discord.com/api/webhooks/xxx/zzz
    DISCORD_BOT_TOKEN=your_discord_bot_token_here
    DISCORD_CHATBOT_CHANNEL_ID=000000000000000000

### B. Update `infra/docker-compose.yml`
1. In `jarvis-n8n` `environment:`, add:
   - `DISCORD_VOICE_LOG_WEBHOOK_URL=${DISCORD_VOICE_LOG_WEBHOOK_URL:-}`
2. Add a lightweight `jarvis-discord-bridge` service (Node.js 20 Alpine, `restart: unless-stopped`, bound to internal Docker network only — no public ports exposed) built from `../scripts/discord-bridge`:
   - Passes `DISCORD_BOT_TOKEN`, `DISCORD_CHATBOT_CHANNEL_ID`, and `N8N_CHATBOT_WEBHOOK_URL=http://jarvis-n8n:5678/webhook/discord-chat`.
   - Depends on `jarvis-n8n`.

### C. Update `.github/workflows/deploy.yml`
- Initialize `/home/jarvis/app/infra/user_data/discord_chat_history.json` with `{"messages":[]}` if it does not exist yet (with `1000:1000` ownership).
- Ensure `docker compose up -d --build --remove-orphans` builds `jarvis-discord-bridge` cleanly on deploy.

---

## 3. Patch `workflows/01-voice-agent-core.json`
1. In `Dispatch Discord Confirmation`:
   - Read `$env.DISCORD_VOICE_LOG_WEBHOOK_URL` (NEVER `process.env`, NEVER fallback to `DISCORD_BRIEFING_WEBHOOK_URL`).
   - If `DISCORD_VOICE_LOG_WEBHOOK_URL` is empty or contains `/xxx/`, skip Discord dispatch cleanly with a non-blocking warning.
   - Keep `notify_discord` controllable via the request body (default `true` when `DISCORD_VOICE_LOG_WEBHOOK_URL` is configured, so `#logs-voix` logs every voice command while `#briefing` stays 100% clean).

---

## 4. Lightweight Discord Gateway Bridge (`scripts/discord-bridge/`)
Create a minimal, dependency-light Node.js service (`package.json`, `Dockerfile`, `index.js`) using `discord.js` (v14):
- Connects with intents `Guilds`, `GuildMessages`, `MessageContent`.
- Listens strictly to `messageCreate` where:
  - `message.channelId === process.env.DISCORD_CHATBOT_CHANNEL_ID`
  - `!message.author.bot` (strict anti-loop guard).
- Extracts:
  - `text`: `message.content` (trimmed)
  - `audio_attachment`: first attachment whose `contentType` starts with `audio/` or filename ends with `.ogg`, `.mp3`, `.wav`, `.m4a`, `.webm` (captures `{ url, content_type, name }`).
- If neither `text` nor `audio_attachment` is present, ignore the message.
- Sends `message.channel.sendTyping()` while waiting.
- POSTs JSON payload to `N8N_CHATBOT_WEBHOOK_URL`:

    {
      "message_id": "string",
      "channel_id": "string",
      "author": "string",
      "text": "string",
      "audio_attachment": { "url": "string", "content_type": "string", "name": "string" } | null,
      "timestamp": "ISO-8601"
    }

- Receives `{ "reply": "..." }` from n8n and replies in `#chatbot` (splitting cleanly into chunks <= 1900 chars if needed to respect Discord's 2000-char limit).
- If `DISCORD_BOT_TOKEN` is missing or placeholder (`your_discord_bot_token_here`), log a clear info message and exit cleanly without crash-looping endlessly (or sleep gracefully).

---

## 5. Create `workflows/04-discord-chatbot.json`
- **Workflow Deterministic ID:** `"id": "04-discord-chatbot"`
- **Workflow Name:** `"04 - Discord Persistent Chatbot (Text & Audio)"`

### Nodes Architecture:
1. **Webhook Trigger (`Webhook Discord Chat`):** `POST /webhook/discord-chat` (`responseMode: "responseNode"`).
2. **Code Node (`Prepare Chat Context & Audio`):**
   - Loads `/home/node/user_data/discord_chat_history.json` (initializes `{"messages":[]}` if missing), `trackers_registry.json`, and `memory_rules.json`.
   - Keeps a sliding window of the last **20 messages** (`role: "user" | "assistant"`, `content`, `timestamp`) for context injection.
   - If `audio_attachment` is present:
     - Downloads the audio file via `https` and transcribes it using the Gemini REST API (`models/gemini-3.5-flash-lite`, `inlineData` base64 audio + prompt `"Transcris fidèlement cet audio en français, sans commentaire."`) using `$env.GEMINI_API_KEY` (or a dedicated n8n HTTP Request / Gemini node with `GEMINI_CREDENTIAL_ID` so no extra env var is needed if preferred).
     - Combines any text + transcription into `user_message`.
   - Supports a built-in `/reset` or `!reset` command: clears `discord_chat_history.json` and immediately returns `"Historique de conversation réinitialisé."`.
3. **AI Agent (`Jarvis Discord Chatbot` with `models/gemini-3.5-flash-lite` / `GEMINI_CREDENTIAL_ID`):**
   - System prompt: Direct executive tone (French), injects `current_date`, `trackers_registry`, `memory_rules`, and the sliding window `conversation_history`.
   - Equipped with a single HTTP/Code tool `execute_jarvis_action` that calls `http://jarvis-n8n:5678/webhook/voice-command` (`{ "command": "...", "notify_discord": false }`) whenever the user asks to create/update a tracker, manage Google Tasks/Calendar/Gmail/Drive/Docs/Sheets, or update a memory rule. This avoids duplicating all 21 tools in two workflows while giving `#chatbot` full operational power!
4. **Code Node (`Persist Chat History`):**
   - Appends `{ role: "user", content: user_message, timestamp }` and `{ role: "assistant", content: reply, timestamp }` to `/home/node/user_data/discord_chat_history.json`.
   - Trims stored history to the last 40 entries (keeping the file lightweight).
5. **Respond to Webhook Node:**
   - Returns `{ "status": "ok", "reply": "...", "transcribed": boolean, "timestamp": "ISO-8601" }`.

---

## 6. Acceptance Criteria & Handover
1. Update `infra/docker-compose.yml`, `infra/.env.exemple`, `.github/workflows/deploy.yml`, `workflows/01-voice-agent-core.json`, `AGENTS.md`, and `README.md` (including the real-time Discord voice channel bonus note).
2. Create `scripts/discord-bridge/` (`package.json`, `Dockerfile`, `index.js`) and `workflows/04-discord-chatbot.json`.
3. Run `.agents/hooks/pre-commit-secrets.sh` to verify valid JSON syntax and 0 hardcoded secrets/tokens.
4. Output the standard `IMPLEMENTATION REPORT` block for Loop 1 synchronization.