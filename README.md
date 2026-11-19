# 🧠 Jarvis Assistant — ADHD Executive Prosthesis & AI Orchestrator

Jarvis is an event-driven personal assistant and executive function prosthesis built with **n8n**, **Google Gemini (`gemini-3.5-flash-lite`)**, **Laravel 11 (PHP 8.3 REST API Gateway)**, **Vue.js 3 + Tailwind CSS (Cockpit & Android PWA)**, **Docker Compose**, and **GitHub Actions CI/CD**.

It is designed around two core goals:
1. **Clinical ADHD Cognitive Accessibility & Direct Executive Tone:** Reduce cognitive friction (< 2 minutes per interaction) through architectural noise filtering, proactive orchestration, and a crisp, factual **Chief-of-Staff executive tone** (zero patronizing/coddling language, zero Night Amnesty rollovers, full visibility on all active Google Tasks `@default` including undated ones, and 45-min timed Ableton Live sandboxes).
2. **100% Generic & Open-Source Architecture:** Zero hardcoded personal trackers or secrets in Git. All life/work trackers (`CYCLE_STATE_MACHINE`, `COLLECTION_TABLE`, `ACTIVITY_LOG`), prompt-driven morning `tracker_mutations`, monthly memory archives (`archive_<tracker_id>.json`), and contextual memory scopes are created and mutated dynamically at runtime in an isolated `/home/node/user_data/` volume, alongside unified **Google Workspace** (Gmail, Calendar, Tasks, Drive, Docs, Sheets) connectivity.

---

## 🏗️ System Architecture & Workflows

| Workflow File | ID | Trigger | Description |
| :--- | :--- | :--- | :--- |
| `workflows/01-voice-agent-core.json` | `01-voice-agent-core` | `POST /webhook/voice-command` | Core Voice AI Agent (direct executive tone) equipped with the **4 Universal Meta-Tools** (`create_or_update_tracker_schema`, `mutate_tracker_record`, `query_tracker_records`, `update_memory_rule`) and **17 Native Google Workspace Tools** across **Google Tasks** (`@default`), **Google Calendar** (`primary`), **Gmail** (search, read, create draft), **Google Drive** (search, create text file), **Google Docs** (read, create, append), and **Google Sheets** (read, append), logging voice commands to `#logs-voix` (`DISCORD_VOICE_LOG_WEBHOOK_URL`). |
| `workflows/02-morning-briefing.json` | `02-morning-briefing` | Cron `08:30` (`Europe/Paris`) & `POST /webhook/morning-briefing` | Aggregates **Google Calendar** (`primary`), **Gmail** (24h messages), **Google Tasks** (`@default` including undated tasks), and `/home/node/user_data/` trackers; executes prompt-driven `tracker_mutations` with same-day idempotency, dispatches a 5-section executive briefing to `#briefing` (`DISCORD_BRIEFING_WEBHOOK_URL`), and creates urgent email tasks in Google Tasks. |
| `workflows/03-memory-compression.json` | `03-memory-compression` | Cron `03:00` 1st of month (`0 3 1 * *`) & `POST /webhook/compress-memory` | Purges expired volatile memory rules, summarizes tracker records older than 30 days (while preserving open/pending `COLLECTION_TABLE` quotes/leads) via `gemini-3.5-flash-lite` into `/home/node/user_data/archive_<tracker_id>.json`, and trims active `data_<tracker_id>.json` files. |
| `workflows/04-discord-chatbot.json` | `04-discord-chatbot` | `POST /webhook/discord-chat` (via `jarvis-discord-bridge`) | Persistent multi-turn Discord Chatbot (`#chatbot`) supporting **text messages** and **audio voice notes** (`.ogg`, `.mp3`, `.wav`, `.m4a` transcribed via Gemini `gemini-3.5-flash-lite`), backed by `/home/node/user_data/discord_chat_history.json` (sliding window + `/reset`) and delegating operational actions to `01-voice-agent-core` via `execute_jarvis_action`. |

---

## 🛡️ REST API Gateway (`backend/` — Laravel 11 & Sanctum)

`jarvis-backend` (`https://jarvis-api.victor-giroud.fr` -> `127.0.0.1:5679`) is a low-RAM (< 80 MB idle) **PHP 8.3-FPM (`ondemand`) + Nginx Alpine** container running as UID/GID `1000:1000`:
- **Authentication (`Laravel Sanctum` + SQLite):** `GET /api/health`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`, seeded idempotently via `php artisan jarvis:seed-owner`.
- **Dynamic Trackers (`TrackerStorageService` with `LOCK_EX`):** `GET /api/trackers`, `GET /api/trackers/{trackerId}` (`?include_archive=true`), `POST /api/trackers/{trackerId}/records`, `PATCH /api/trackers/{trackerId}/records/{recordId}`, `DELETE /api/trackers/{trackerId}/records/{recordId}`, and `POST /api/trackers/{trackerId}/cycle` (`advance`, `set`, and zero-guilt `skip` writing a volatile `cycle_overrides` rule expiring tomorrow in `memory_rules.json`).
- **Context Memory (`MemoryRuleService`):** `GET /api/memory` (with automatic expired volatile purge), `POST /api/memory/rules`, `DELETE /api/memory/rules/{ruleId}`.
- **n8n Orchestrator & Gemini Live Bridge (`N8nGatewayService` & `GeminiLiveService`):** `POST /api/jarvis/command`, `GET /api/jarvis/chat/history` (shared with `#chatbot`), `POST /api/jarvis/chat`, `POST /api/jarvis/briefing/trigger`, `POST /api/jarvis/live/session` (ephemeral Gemini Multimodal Live session & dynamic system instruction), and `POST /api/jarvis/live/tool` (real-time voice function calling into trackers, memory rules, and n8n).

---

## 💻 Dynamic Vue.js 3 Cockpit & Android PWA (`frontend/`)

`jarvis-dashboard` (`https://jarvis-dashboard.victor-giroud.fr` -> `127.0.0.1:5680`) is a lightweight **Vue.js 3 (Composition API `<script setup>`) + Vite + Tailwind CSS** SPA served by `nginx:1.27-alpine` (~15–25 MB RAM):
- **100% Schema-Driven Trackers:** Dynamically renders `CYCLE_STATE_MACHINE`, `COLLECTION_TABLE`, and `ACTIVITY_LOG` trackers from `GET /api/trackers` without hardcoded tracker IDs.
- **Real-Time Bidirectional Voice ("Gemini Live" WebSocket) & Talkie-Walkie Mode:** Streams 16 kHz PCM microphone audio directly to the Gemini Multimodal Live API WebSocket with gapless 24 kHz PCM voice playback, instant user barge-in/interruption, and live function calling (`execute_jarvis_command`, `mutate_tracker_cycle`, `add_tracker_record`, `manage_memory_rule`, `trigger_morning_briefing`), alongside persistent text/dictation chat shared with Discord `#chatbot` (`discord_chat_history.json`).
- **Installable Android PWA & 1-Tap Voice Shortcut:** Includes `manifest.webmanifest` and `sw.js` with a home-screen shortcut (`/?view=voice&autostart=true`) that opens the voice tab and starts microphone dictation immediately.
- **Observability / LLMOps & Memory Rules Manager:** Displays real-time API health, active trackers/memory counts, and request latency (`ms`), plus full CRUD over `memory_rules.json`.

---

## 🔮 Future Backlog & Bonus Features
- **Real-time Discord Voice Channel (RTC):** Bidirectional real-time voice conversation in a Discord voice channel (Bonus backlog item).

---

## 📂 Repository Structure

```text
├── .agents/
│   ├── hooks/pre-commit-secrets.sh          # Blocks leaked API keys/webhooks & validates workflow JSONs
│   └── skills/                              # Domain & Sprint specifications (Dual-Loop Architecture)
├── .github/workflows/deploy.yml             # Automated SSH deployment & workflow import to OVH VPS
├── backend/                                 # Laravel 11 REST API Gateway (PHP 8.3, Sanctum, SQLite)
├── docs/achitecture.mmd                     # Mermaid system architecture diagram
├── frontend/                                # Vue.js 3 + Tailwind CSS Cockpit & Android PWA (127.0.0.1:5680)
├── infra/
│   ├── docker-compose.yml                   # Production stack (jarvis-n8n, jarvis-discord-bridge, jarvis-backend, jarvis-dashboard)
│   └── .env.exemple                         # Environment template
├── scripts/discord-bridge/                  # Discord #chatbot Gateway bridge (Text + Audio)
└── workflows/                               # Versioned, sanitized n8n workflows
```

---

## 🚀 Deployment & Security

- **CI/CD Pipeline:** Every push to `main` runs `.github/workflows/deploy.yml`, which connects to the OVH VPS via SSH (`jarvis` user), initializes `/home/jarvis/app/infra/user_data/` (`1000:1000` UID), updates containers via `docker compose`, and imports `/workflows/*.json` into `jarvis-n8n`.
- **Pre-Commit Security Verification:**
  ```bash
  ./.agents/hooks/pre-commit-secrets.sh
  ```

