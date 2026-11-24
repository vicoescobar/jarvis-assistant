# AGENTS.md — Master Context & System Architecture: Jarvis Assistant

> **For local deployment and real infrastructure specifics (VPS IP, live domains, SSH paths), read `.agents/local-context.md` if present (gitignored). Never copy values from that file into tracked files.**

## 1. Project Identity & Dual Objective
- **Goal 1 — Low-Friction Executive Orchestrator:** An event-driven personal assistant that minimizes cognitive load, reduces task-initiation friction, filters digital noise across communication channels (Gmail, IMAP/Outlook, Discord), and structures routines with < 2 minutes of daily interaction.
- **Goal 2 — Production-Grade Portfolio Monorepo:** Showcase clean software engineering for technical recruiters: Infrastructure as Code (Docker Compose), automated CI/CD (GitHub Actions via SSH), structured LLM orchestration (Gemini Flash JSON schemas & schema-less Meta-Tools), and a fullstack web cockpit (Laravel 11 REST API + Vue.js 3 PWA with sandboxed HTML/CSS/JS custom views).

---

## 2. Production Infrastructure
- **GitHub Repository (Single Source of Truth):** `https://github.com/vicoescobar/jarvis-assistant`
- **Server:** Linux VPS (`<VPS_IP>`) running Ubuntu & CloudPanel (Nginx Reverse Proxy + Let's Encrypt SSL).
- **System User Isolation:** Dedicated Linux user `jarvis`
  - Working directory: `/home/jarvis/app`
  - SSH key-only authentication (`~/.ssh/github_deploy`)
  - Passwordless sudoers restricted strictly to `/snap/bin/docker` and `/usr/bin/chown` (`/etc/sudoers.d/jarvis-docker`).
- **Docker Runtime:** Installed via Ubuntu Snap.
  - Volumes `/home/jarvis/app/infra/n8n_data`, `/home/jarvis/app/infra/user_data`, and `/home/jarvis/app/infra/backend_data` MUST maintain `1000:1000` ownership (internal `node` user in n8n container and `www-data` UID `1000` in `jarvis-backend`).
  - `NODE_FUNCTION_ALLOW_BUILTIN=fs,path,crypto,http,https,url` and `N8N_BLOCK_ENV_ACCESS_IN_NODE=false` enabled on `jarvis-n8n` so Meta-Tools and workflows can read/write runtime JSON files in `/home/node/user_data/` and dispatch notifications to `DISCORD_BRIEFING_WEBHOOK_URL` (`#briefing`) and `DISCORD_VOICE_LOG_WEBHOOK_URL` (`#logs-voix`).
  - `jarvis-discord-bridge` (`scripts/discord-bridge/`, Node.js 20 Alpine, `discord.js` v14) runs on the internal Docker network, listens to `#chatbot` (`DISCORD_CHATBOT_CHANNEL_ID`) for text messages and audio voice notes, and forwards them to `http://jarvis-n8n:5678/webhook/discord-chat`.
  - `jarvis-backend` (`backend/`, PHP 8.3 FPM `ondemand` + Nginx Alpine, Laravel 11 + Sanctum + SQLite) runs as UID/GID `1000:1000`, mounts `./user_data:/var/www/user_data` (with atomic `LOCK_EX` writes) and `./backend_data:/var/www/storage/app/sqlite`, and proxies orchestration actions to `http://jarvis-n8n:5678/webhook/...`.
  - `jarvis-dashboard` (`frontend/`, Vue.js 3 + Vite + Tailwind CSS + PWA Service Worker served via `nginx:1.27-alpine` on `127.0.0.1:5680:80`) renders 100% schema-driven dynamic trackers, sandboxed HTML/CSS/JS custom micro-app views (`<iframe sandbox="allow-scripts allow-modals">` + `window.jarvis` SDK), memory rules, LLMOps diagnostics, and voice commands with 1-tap Android PWA shortcut (`/?view=voice&autostart=true`).
- **Reverse Proxy & SSL:** CloudPanel (Nginx) with Let's Encrypt SSL (exact domain names in `.agents/local-context.md`):
  - `https://<MAIN_DOMAIN>/` -> `http://127.0.0.1:5678` (`jarvis-n8n`)
  - `https://<API_DOMAIN>/` -> `http://127.0.0.1:5679` (`jarvis-backend`)
  - `https://<DASHBOARD_DOMAIN>/` -> `http://127.0.0.1:5680` (`jarvis-dashboard`)
- **CI/CD Pipeline:** Every push to `main` triggers `.github/workflows/deploy.yml`, which connects via SSH as `jarvis`, pulls `main`, initializes `trackers_registry.json`, `memory_rules.json`, and `discord_chat_history.json` in `infra/user_data` plus `database.sqlite` in `infra/backend_data` if missing, enforces `1000:1000` on `n8n_data`, `user_data`, and `backend_data`, runs `docker compose up -d --build --remove-orphans`, executes `php artisan migrate --force && php artisan jarvis:seed-owner` on `jarvis-backend`, and auto-imports versioned workflows via `n8n import:workflow --separate --input=/home/node/workflows/`.

---

## 3. Functional Ecosystem & Data Flows
1. **Mobile & Voice Entry Points:**
   - Mobile Voice Assistant / Webhooks & 1-tap PWA voice view (`/?view=voice&autostart=true`).
   - **The `#jarvis` Voice Bridge:** Voice commands prefixed with `#jarvis` added to Google Tasks are polled by n8n, parsed by Gemini Flash, executed on the server, and reported back via Discord.
   - **Mobile Battery Optimization Constraint:** Never run background daemons on the mobile device. All crons/timers run on the VPS; notifications go through push-backed channels (Discord).
2. **Aggregated Sources:**
   - **Google Workspace (`<USER_EMAIL>`):** Unified integration across **Gmail** (daily 24h analysis & dynamic `memory_rules.json` filtering), **Google Calendar** (today's events in morning briefing), **Google Tasks** (`@default` list read for context/deduplication & automatic action creation from urgent emails), and **Google Drive / Docs / Sheets**.
   - **Secondary IMAP / Microsoft 365:** Integrated via IMAP, OAuth2, or automatic forwarding into the unified morning briefing.
   - **Community / Organization Discord Channels:** Daily digest of announcements and deadlines pushed to n8n webhook.
3. **Cognitive Accessibility & Low-Friction Executive UX Guardrails:**
   - **Proactive Point-of-Performance Delivery:** Push actionable morning briefings (5 well-spaced sections: `Agenda`, `Mails importants`, `Tâches en cours`, `Trackers`, `Suggestion`) instead of waiting for the user to open a dashboard.
   - **Direct Executive Tone (No Patronizing Copy):** Cognitive accessibility lives in the architecture (noise filtering, prioritization, low friction, generic prompt-driven `tracker_mutations`), never in coddling or infantilizing copy. Strictly ban phrases like *"zéro pression"*, *"au calme"*, *"sans aucune culpabilité"*, *"si l'énergie n'y est pas"*, *"profite de cette liberté"*, and do NOT include *"Plan Si/Alors"* in the morning briefing.
   - **Non-Punitive State Management & Full Task Visibility:** No red warning badges or broken streak counters. Unfinished tasks are NEVER automatically moved or deleted overnight; all active tasks in `@default` (including undated ones) are surfaced in the morning briefing.
   - **Timed Creative Sandbox Protocol (Peak-End Principle):** Frame open-ended creative or studio sessions strictly as 45-minute timed sandboxes ("Sound Design" OR "Arrangement") closing with a tangible 15-second deliverable export.

---

## 4. Repository Structure & Active Skills
- `AGENTS.md` : Master context and architecture entry point for AI coding agents
- `.agents/local-context.md` : Gitignored private file holding real VPS IP, live domains, and local environment specifics
- `.agents/local-context.example.md` : Public sanitized template for `.agents/local-context.md`
- `.agents/rules.md` : Quick-reference pointer to `AGENTS.md` and active skills
- `.agents/hooks/pre-commit-secrets.sh` : Deterministic security hook blocking API keys, private IPs/emails, `.agents/local-context.md`, `*.local.md`, and invalid JSON in `workflows/*.json`
- `.agents/skills/dual-loop-workflow/SKILL.md` : Protocol for synchronizing specifications and implementation reports between Loop 1 (Architecture) and Loop 2 (IDE Execution)
- `.agents/skills/executive-ux-guardrails/SKILL.md` : Cognitive accessibility, low-friction executive UX, and prompt engineering rules
- `.agents/skills/n8n-workflow-builder/SKILL.md` : n8n JSON workflow conventions, expression safety, and Gemini structured schema rules
- `.agents/skills/vps-infra-deploy/SKILL.md` : VPS, Snap Docker, and CloudPanel/Nginx reverse-proxy rules
- `.agents/skills/apple-design/SKILL.md` : Fluid physical motion, spring physics, typography, and translucent materials guidelines
- `.agents/skills/unslop/SKILL.md` : UI craft and anti-slop visual design review guidelines
- `.github/workflows/deploy.yml` : SSH Auto-deploy to VPS on push to `main`
- `docs/achitecture.mmd` & `docs/ARCHITECTURE_DEEP_DIVE.md` : Mermaid system diagram & comprehensive engineering case study
- `infra/docker-compose.yml` : Production containers (`jarvis-n8n` on `127.0.0.1:5678`, `jarvis-backend` on `127.0.0.1:5679`, `jarvis-dashboard` on `127.0.0.1:5680`, and internal `jarvis-discord-bridge`)
- `infra/user_data/` : Private gitignored runtime volume (`trackers_registry.json`, `data_<tracker_id>.json`, `archive_<tracker_id>.json`, `memory_rules.json`, `discord_chat_history.json`)
- `infra/backend_data/` : Private gitignored SQLite persistence volume (`database.sqlite`) for `jarvis-backend`
- `infra/.env.exemple` : Sanitized environment template
- `workflows/` : Versioned JSON exports of n8n workflows (`01-voice-agent-core.json`, `02-morning-briefing.json`, `03-memory-compression.json`, `04-discord-chatbot.json`)
- `scripts/discord-bridge/` : Lightweight Node.js 20 (`discord.js` v14) Gateway bridge forwarding `#chatbot` text & audio voice notes to n8n
- `backend/` : Laravel 11 REST API Gateway (PHP 8.3 FPM `ondemand` + Nginx Alpine, Sanctum, SQLite, `TrackerStorageService`, `CustomViewSanitizer`, `MemoryRuleService`, `N8nGatewayService`)
- `frontend/` : Dynamic Vue.js 3 + Tailwind CSS Cockpit & Android PWA (`nginx:1.27-alpine`, Pastel Cream Light & Graphite Dark themes, Sandboxed HTML/CSS/JS custom views)

---

## 5. Architecture & Feature Status
- **Infrastructure & CI/CD (ACTIVE):** Docker Compose multi-container stack bound to `127.0.0.1`, CloudPanel SSL reverse proxy, isolated `jarvis` Linux user, and SSH GitHub Actions auto-deploy with dynamic n8n OAuth2 credential mapping.
- **Schema-less Dynamic Trackers & Google Workspace Engine (ACTIVE):**
  - 5 universal local Meta-Tools in `workflows/01-voice-agent-core.json`: `create_or_update_tracker_schema`, `mutate_tracker_record` (including real `delete` and zero-guilt cycle shift), `query_tracker_records`, `update_memory_rule`, and `update_tracker_custom_view` (with server-side `new Function('jarvis', js)` syntax validation).
  - 17 native Google Workspace tools (Tasks, Calendar, Gmail, Drive, Docs, Sheets).
  - Proactive `08:30` Morning Briefing (`workflows/02-morning-briefing.json`) with prompt-driven `tracker_mutations`, Monthly 30-day Memory Compression (`workflows/03-memory-compression.json`), and Persistent Multi-Turn Discord Chatbot (`workflows/04-discord-chatbot.json` supporting text and voice notes).
- **Fullstack Cockpit, API Gateway & Sandboxed Micro-Apps (ACTIVE):**
  - Laravel 11 REST API Gateway (`backend/`) with Sanctum auth, atomic `LOCK_EX` JSON storage, automatic filtering of soft-deleted records, and custom view sanitizer (`PATCH/DELETE /api/trackers/{id}/view`).
  - Vue.js 3 + Tailwind CSS Cockpit & PWA (`frontend/`) with Pastel & Cream Light / Graphite Dark theme toggle, Trackers-first home screen, read-only default cycle cards, `<iframe sandbox="allow-scripts allow-modals">` custom tracker micro-apps powered by the `window.jarvis` postMessage SDK, lean Markdown/TTS Voice view, read/delete-only Memory view, and Debug diagnostics view.

---

## 6. Mandatory Development Rules for AI Agents
1. **Security First (Public Repo + Auto-Deploy):** Never hardcode API keys (`AIza...`), Discord tokens, webhook URLs, server IPs, or personal email addresses in `workflows/*.json`, `infra/`, `AGENTS.md`, `.agents/skills/`, or source code. Always use `$env`, n8n Credential references, or read local values from `.agents/local-context.md` (gitignored).
2. **Workflow Sanitization:** Whenever generating or modifying an n8n workflow in `/workflows/*.json`, ensure valid JSON syntax and run `./.agents/hooks/pre-commit-secrets.sh` before committing.
3. **Skill Routing:** Always consult `.agents/skills/` for domain-specific constraints before editing infrastructure, n8n workflows, or user-facing prompts/UI.

## 7. End-of-Feature Handover Protocol
When the user asks for an implementation summary or handover report at the end of a feature/step, output a concise Markdown block containing:
1. **Completed Deliverables:** Exact files created/updated and workflows deployed.
2. **Technical Decisions & Deviations:** Any change from the initial skill spec and why.
3. **Issues & Fixes:** Bugs, environment quirks (Snap Docker, CloudPanel, APIs), and how they were solved.
4. **Current Production State:** What is live and verified in production.
