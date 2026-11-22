# .agent — Master Context & System Architecture: Jarvis Assistant

## 1. Project Identity & Dual Objective
- **Owner:** Victor (Computer Science student & Fullstack Developer: Java/Spring Boot, PHP/Laravel, Vue.js 3).
- **Goal 1 — Personal ADHD Executive Prosthesis:** An event-driven assistant that reduces cognitive load, eliminates task-initiation paralysis, filters digital noise (Gmail, school Outlook, promo Discord), and structures routines with < 2 minutes of daily friction and zero guilt.
- **Goal 2 — Production-Grade Portfolio Monorepo:** Showcase clean software engineering for technical recruiters: Infrastructure as Code (Docker Compose), automated CI/CD (GitHub Actions via SSH), structured LLM orchestration (Gemini Flash JSON schemas), and a fullstack web cockpit (Vue.js + REST API).

---

## 2. Production Infrastructure (Sprint 0 — ACTIVE)
- **GitHub Repository (Single Source of Truth):** `https://github.com/vicoescobar/jarvis-assistant`
- **Server:** OVH VPS (`57.129.174.162`) running Ubuntu & CloudPanel.
- **System User Isolation:** Dedicated Linux user `jarvis`
  - Working directory: `/home/jarvis/app`
  - SSH key-only authentication (`~/.ssh/github_deploy`)
  - Passwordless sudoers restricted strictly to `/snap/bin/docker` and `/usr/bin/chown` (`/etc/sudoers.d/jarvis-docker`).
- **Docker Runtime:** Installed via Ubuntu Snap.
  - Volumes `/home/jarvis/app/infra/n8n_data`, `/home/jarvis/app/infra/user_data`, and `/home/jarvis/app/infra/backend_data` MUST maintain `1000:1000` ownership (internal `node` user in n8n container and `www-data` UID `1000` in `jarvis-backend`).
  - `NODE_FUNCTION_ALLOW_BUILTIN=fs,path,crypto,http,https,url` and `N8N_BLOCK_ENV_ACCESS_IN_NODE=false` enabled on `jarvis-n8n` so Meta-Tools and workflows can read/write runtime JSON files in `/home/node/user_data/` and dispatch notifications to `DISCORD_BRIEFING_WEBHOOK_URL` (`#briefing`) and `DISCORD_VOICE_LOG_WEBHOOK_URL` (`#logs-voix`).
  - `jarvis-discord-bridge` (`scripts/discord-bridge/`, Node.js 20 Alpine, `discord.js` v14) runs on the internal Docker network, listens to `#chatbot` (`DISCORD_CHATBOT_CHANNEL_ID`) for text messages and audio voice notes, and forwards them to `http://jarvis-n8n:5678/webhook/discord-chat`.
  - `jarvis-backend` (`backend/`, PHP 8.3 FPM `ondemand` + Nginx Alpine, Laravel 11 + Sanctum + SQLite) runs as UID/GID `1000:1000`, mounts `./user_data:/var/www/user_data` (with atomic `LOCK_EX` writes) and `./backend_data:/var/www/storage/app/sqlite`, and proxies orchestration actions to `http://jarvis-n8n:5678/webhook/...`.
  - `jarvis-dashboard` (`frontend/`, Vue.js 3 + Vite + Tailwind CSS + PWA Service Worker served via `nginx:1.27-alpine` on `127.0.0.1:5680:80`) renders 100% schema-driven dynamic trackers, memory rules, LLMOps observability, and shared chat/voice with 1-tap Android PWA shortcut (`/?view=voice&autostart=true`).
- **Reverse Proxy & SSL:** CloudPanel (Nginx) with Let's Encrypt SSL:
  - `https://jarvis.victor-giroud.fr/` -> `http://127.0.0.1:5678` (`jarvis-n8n`)
  - `https://jarvis-api.victor-giroud.fr/` -> `http://127.0.0.1:5679` (`jarvis-backend`)
  - `https://jarvis-dashboard.victor-giroud.fr/` -> `http://127.0.0.1:5680` (`jarvis-dashboard`)
- **Orchestrator:** `jarvis-n8n` (`docker.n8n.io/n8nio/n8n:latest`) with lifetime Community License unlocked (Folders & Advanced Debugging enabled).
- **CI/CD Pipeline:** Every push to `main` triggers `.github/workflows/deploy.yml`, which connects via SSH as `jarvis`, pulls `main`, initializes `trackers_registry.json`, `memory_rules.json`, and `discord_chat_history.json` in `infra/user_data` plus `database.sqlite` in `infra/backend_data` if missing, enforces `1000:1000` on `n8n_data`, `user_data`, and `backend_data`, runs `docker compose up -d --build --remove-orphans`, executes `php artisan migrate --force && php artisan jarvis:seed-owner` on `jarvis-backend`, and auto-imports versioned workflows via `n8n import:workflow --separate --input=/home/node/workflows/`.

---

## 3. Functional Ecosystem & Data Flows
1. **Mobile Entry Point (Xiaomi Redmi Note 14 5G - HyperOS):**
   - Power-button Gemini Voice Assistant -> Native Google Workspace extensions (`@Gmail`, `@Google Tasks`, `@Google Calendar`).
   - **The `#jarvis` Voice Bridge:** Voice commands prefixed with `#jarvis` added to Google Tasks are polled by n8n every 5 minutes, parsed by Gemini Flash, scheduled/executed on the server, and reported back via Discord/WhatsApp.
   - **HyperOS Constraint:** Never run background daemons on the phone (PowerKeeper kills them). All crons/timers run on the OVH VPS; notifications go through FCM-backed channels (Discord / WhatsApp).
2. **Aggregated Sources:**
   - **Primary Google Workspace (`victorgiroud4@gmail.com`):** Unified integration across **Gmail** (daily 24h analysis, internship/job search priority `stage`, `alternance`, `candidature`, `entretien` + dynamic `memory_rules.json` filtering), **Google Calendar** (today's events in morning briefing), **Google Tasks** (`@default` list read for context/deduplication & automatic action creation from urgent emails), and **Google Drive / Docs / Sheets**.
   - **School Microsoft 365 / Outlook:** Integrated via IMAP (`outlook.office365.com:993`), OAuth2, or automatic forwarding (`+ecole` alias) into the unified morning briefing.
   - **Major/Promo Discord Server (No Admin Rights):** Daily digest of announcements/deadlines using `DiscordChatExporter` CLI (run locally or via residential proxy -> pushed to n8n webhook to bypass Cloudflare datacenter IP blocks on OVH) or Discord Announcement Channel following.
3. **Clinical ADHD Guardrails (Must be enforced in all prompts & UI):**
   - **Point of Performance (Barkley):** Push actionable notifications (5 well-spaced sections: `Agenda`, `Mails importants`, `Tâches en cours`, `Trackers`, `Suggestion`) instead of waiting for the user to open a dashboard.
   - **Direct Executive Tone (No Patronizing Copy):** ADHD accommodation lives in the architecture (noise filtering, prioritization, low friction, generic prompt-driven `tracker_mutations`), never in coddling or infantilizing copy. Strictly ban phrases like *"zéro pression"*, *"au calme"*, *"sans aucune culpabilité"*, *"si l'énergie n'y est pas"*, *"profite de cette liberté"*, and do NOT include *"Plan Si/Alors"* in the morning briefing.
   - **Zero Shame / No Night Amnesty & Full Task Visibility (Sirois & Pychyl):** No red badges or broken streaks. Unfinished tasks are NEVER automatically moved or deleted; all active tasks in `@default` (including undated ones) are surfaced in the morning briefing.
   - **Ableton Live Sandbox Protocol (Peak-End Rule):** Prevent post-hyperfocus perfectionism aversion. Frame music sessions strictly as 45-minute timed sandboxes ("Sound Design" OR "Arrangement"). At 45 minutes, n8n pings the user to export a 15-second MP3 snippet to close on a tangible win.

---

## 4. Repository Structure
- `.agent` : Master context file for AI coding agents
- `.agents/hooks/pre-commit-secrets.sh` : Blocks API keys/tokens in `workflows/*.json` and source code
- `.agents/skills/adhd-ux-guardrails/SKILL.md` : Clinical ADHD UX & prompt rules
- `.agents/skills/dual-loop-workflow/SKILL.md` : Synchronizing specs and implementation reports between NotebookLM and Antigravity IDE
- `.agents/skills/n8n-workflow-builder/SKILL.md` : n8n JSON & Gemini structured schema rules
- `.agents/skills/sprint1-dynamic-trackers-engine/SKILL.md` : Schema-less Dynamic Trackers & Context Memory Engine (Step 1.1)
- `.agents/skills/sprint1-morning-briefing-discord/SKILL.md` : Discord Outbound Channel & Adaptive Morning Briefing (Step 1.2)
- `.agents/skills/sprint1-google-workspace-memory/SKILL.md` : Google Workspace Sync & Long-Term Memory Compression (Step 1.3)
- `.agents/skills/sprint1-briefing-polish/SKILL.md` : Prompt-Driven Morning Mutations (`tracker_mutations`), Direct Executive Tone & Full Google Tasks Visibility (Step 1.3 Polish)
- `.agents/skills/sprint1-voice-google-tasks/SKILL.md` : Native Google Tasks Voice Tools (`google_tasks_list`, `google_tasks_create`, `google_tasks_complete`, `google_tasks_delete`) in `01-voice-agent-core.json`
- `.agents/skills/sprint1-voice-full-workspace/SKILL.md` : Full Google Workspace Native Voice Tools (Calendar, Gmail, Drive, Docs, Sheets + Tasks) in `01-voice-agent-core.json`
- `.agents/skills/sprint1-discord-cleanup-chatbot/SKILL.md` : Discord Channel Separation (`#briefing` vs `#logs-voix`) & Persistent Discord Chatbot (`#chatbot` Text + Audio via `scripts/discord-bridge/` & `04-discord-chatbot.json`)
- `.agents/skills/sprint3-laravel-api-gateway/SKILL.md` : Laravel 11 REST API Gateway (`backend/`, Sanctum, SQLite, `user_data` & n8n webhook bridge)
- `.agents/skills/sprint3-vue-dashboard-pwa/SKILL.md` : Dynamic Vue.js 3 + Tailwind CSS Cockpit & Android PWA (`frontend/`, `127.0.0.1:5680:80`)
- `.agents/skills/sprint4-dashboard-refonte/SKILL.md` : Dashboard Refonte (Pastel Cream Light Theme, Trackers-First Home, Lean Voice View with Markdown/TTS, Read/Delete-only Memory View, Debug View & Gemini Live Removal)
- `.agents/skills/sprint4-agent-custom-views/SKILL.md` : Agent-Generated Custom Tracker Views (HTML/CSS + `update_tracker_custom_view` Meta-Tool)
- `.agents/skills/sprint4-sandboxed-js-views/SKILL.md` : Sandboxed HTML/CSS/JS Custom Tracker Views (`<iframe sandbox="allow-scripts">` + Generic `window.jarvis` postMessage SDK + Server-side JS Syntax Validation)
- `.agents/skills/vps-infra-deploy/SKILL.md` : OVH VPS, Snap Docker & CloudPanel rules
- `.github/workflows/deploy.yml` : SSH Auto-deploy to OVH VPS on push to `main`
- `docs/achitecture.mmd` : Mermaid system diagram
- `infra/docker-compose.yml` : Production containers (`jarvis-n8n` on `127.0.0.1:5678`, `jarvis-backend` on `127.0.0.1:5679`, `jarvis-dashboard` on `127.0.0.1:5680`, and internal `jarvis-discord-bridge`)
- `infra/user_data/` : Private gitignored runtime volume (`trackers_registry.json`, `data_<tracker_id>.json`, `archive_<tracker_id>.json`, `memory_rules.json`, `discord_chat_history.json`)
- `infra/backend_data/` : Private gitignored SQLite persistence volume (`database.sqlite`) for `jarvis-backend`
- `infra/.env.exemple` : Environment template
- `workflows/` : Versioned JSON exports of n8n workflows (`01-voice-agent-core.json`, `02-morning-briefing.json`, `03-memory-compression.json`, `04-discord-chatbot.json`)
- `scripts/discord-bridge/` : Lightweight Node.js 20 (`discord.js` v14) Gateway bridge forwarding `#chatbot` text & audio voice notes to n8n
- `backend/` : Sprint 3 (Step 3.1) Laravel 11 REST API Gateway (PHP 8.3 FPM `ondemand` + Nginx Alpine, Sanctum, SQLite)
- `frontend/` : Sprint 3 (Step 3.2) Dynamic Vue.js 3 + Tailwind CSS Cockpit & Android PWA (`nginx:1.27-alpine`)

---

## 5. Roadmap & Sprints
- **Sprint 0 (DONE):** Docker n8n infra, CloudPanel SSL reverse proxy (`https://jarvis.victor-giroud.fr/`), isolated `jarvis` Linux user, GitHub Monorepo, and SSH GitHub Actions auto-deploy.
- **Sprint 1 (DONE — Vital MVP, Dynamic Trackers Engine, Full Google Workspace & Discord Chatbot):**
  - **Step 1.1 (DONE):** Private `user_data` volume + Schema-less Dynamic Trackers & Context Memory Engine (`workflows/01-voice-agent-core.json` exposing `POST /webhook/voice-command` with the 4 universal Meta-Tools: `create_or_update_tracker_schema`, `mutate_tracker_record`, `query_tracker_records`, `update_memory_rule`).
  - **Step 1.2 (DONE):** Private Discord notification channel (`DISCORD_BRIEFING_WEBHOOK_URL`) + Adaptive & Negotiable Morning Briefing (`workflows/02-morning-briefing.json` triggered at 08:30 Europe/Paris & `POST /webhook/morning-briefing`) + zero-guilt cycle shift (`defaultDelta = 0` on `skip_or_shift_cycle`).
  - **Step 1.3 (DONE):** Google Workspace OAuth2 integration in `workflows/02-morning-briefing.json` (Google Calendar events, Gmail 24h internship/rule triage, Google Tasks `@default` read/deduplication & automatic task creation), removal of Night Amnesty, prompt-driven `tracker_mutations`, Direct Executive Tone, 17 native Google Workspace tools in `01-voice-agent-core.json`, and Monthly Long-Term Memory Compression (`workflows/03-memory-compression.json`).
  - **Sprint 1 Closure (DONE):** Dedicated Discord voice log channel (`#logs-voix` via `DISCORD_VOICE_LOG_WEBHOOK_URL`) and persistent multi-turn Discord Chatbot (`#chatbot` supporting text and audio attachments via `scripts/discord-bridge/` and `workflows/04-discord-chatbot.json` with `/home/node/user_data/discord_chat_history.json`).
- **Sprint 2 (Student & Creative Ecosystem):** School Outlook integration, Promo Discord digest, Ableton 45-min Sandbox timer & MP3 exit ritual, Google Drive "Boîte à vrac".
- **Sprint 3 (Jarvis Cockpit & Analytics — DONE):**
  - **Step 3.1 (DONE):** Lightweight PHP 8.3 / Laravel 11 REST API Gateway (`backend/`, `jarvis-backend` bound to `127.0.0.1:5679` -> `https://jarvis-api.victor-giroud.fr/`) secured with Laravel Sanctum & SQLite, providing `TrackerStorageService` (CRUD + zero-guilt cycle skip with volatile `cycle_overrides` rule), `MemoryRuleService` (CRUD + expired volatile auto-purge), and `N8nGatewayService` (shared `discord_chat_history.json` + internal webhook proxy).
  - **Step 3.2 (DONE):** Dynamic Vue.js 3 + Tailwind CSS Cockpit & Android PWA (`frontend/`, `jarvis-dashboard` bound to `127.0.0.1:5680` -> `https://jarvis-dashboard.victor-giroud.fr/`) featuring 100% schema-driven tracker views, LLMOps observability, memory rules manager, shared Discord/Web chat, and 1-tap voice PWA shortcut (`/?view=voice&autostart=true`).
- **Sprint 4 (Dashboard Refonte & Optimization — DONE):**
  - **Priority 1 (DONE):** Complete removal of Gemini Live WebSocket/backend endpoints, addition of the **Pastel & Cream Light Theme** toggle (`localStorage` + dynamic PWA `theme-color`), Trackers-first home screen, ultra-lean Voice view (single exchange with Markdown rendering and clean TTS Markdown stripping), read/delete-only separated Memory view (`morning_rules` vs `custom_scopes`), and dedicated **Debug** view centralizing manual briefing triggers and LLMOps diagnostics.
  - **Priority 2 & 2.2 (DONE):** Agent-Generated Sandboxed HTML/CSS/JS Custom Tracker Views (`custom_view: { enabled, html, css, js, updated_at }` in `trackers_registry.json`, 5th local Meta-Tool `update_tracker_custom_view` in `01-voice-agent-core.json` with automatic `new Function('jarvis', js)` syntax validation, `CustomViewSanitizer` + `PATCH/DELETE /api/trackers/{id}/view` in Laravel 11 with up to 200 active records filtered of soft-deleted entries, and `<iframe sandbox="allow-scripts">` micro-app renderer `CustomTrackerView.vue` with strict `default-src 'none'` CSP, `window.jarvis` postMessage RPC SDK, auto-resize `ResizeObserver`, live dual-theme CSS variable inheritance, and read-only default `CYCLE_STATE_MACHINE` cards).
- **Bonus / Backlog:** Real-time Discord Voice Channel (RTC) bidirectional voice assistant.

---

## 6. Mandatory Development Rules for AI Agents
1. **Security First (Public Repo + Auto-Deploy):** Never hardcode API keys (`AIza...`), Discord tokens, webhook URLs, or personal credentials in `workflows/*.json`, `infra/`, or source code. Always use `$env` or n8n Credential references.
2. **Workflow Sanitization:** Whenever generating or modifying an n8n workflow in `/workflows/*.json`, ensure valid JSON syntax and strip sensitive webhook tokens or personal identifiers before committing.
3. **Skill Routing:** Always consult `.agents/skills/` for domain-specific constraints before editing infrastructure, n8n workflows, or user-facing prompts/UI.
````[cite: 2, 3, 5, 6, 7]

## 7. End-of-Feature Handover Protocol
When the user asks for an implementation summary or handover report at the end of a feature/step, output a concise Markdown block containing:
1. **Completed Deliverables:** Exact files created/updated and workflows deployed.
2. **Technical Decisions & Deviations:** Any change from the initial skill spec and why.
3. **Issues & Fixes:** Bugs, environment quirks (Snap Docker, CloudPanel, APIs), and how they were solved.
4. **Current Production State:** What is live and verified on `https://jarvis.victor-giroud.fr/`.