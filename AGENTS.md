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
  - Volumes `/home/jarvis/app/infra/n8n_data` and `/home/jarvis/app/infra/user_data` MUST maintain `1000:1000` ownership (internal `node` user in n8n container).
  - `NODE_FUNCTION_ALLOW_BUILTIN=fs,path,crypto` enabled on `jarvis-n8n` so the 4 Meta-Tools can read/write runtime JSON files in `/home/node/user_data/`.
- **Reverse Proxy & SSL:** CloudPanel (Nginx) on domain `https://jarvis.victor-giroud.fr/` (IONOS domain `victor-giroud.fr`) with Let's Encrypt SSL forwarding to `http://127.0.0.1:5678`.
- **Orchestrator:** `jarvis-n8n` (`docker.n8n.io/n8nio/n8n:latest`) with lifetime Community License unlocked (Folders & Advanced Debugging enabled).
- **CI/CD Pipeline:** Every push to `main` triggers `.github/workflows/deploy.yml`, which connects via SSH as `jarvis`, pulls `main`, initializes `trackers_registry.json` and `memory_rules.json` in `infra/user_data` if missing, enforces `1000:1000` on `n8n_data` and `user_data`, runs `docker compose up -d --remove-orphans`, and auto-imports versioned workflows via `n8n import:workflow --separate --input=/home/node/workflows/`.

---

## 3. Functional Ecosystem & Data Flows
1. **Mobile Entry Point (Xiaomi Redmi Note 14 5G - HyperOS):**
   - Power-button Gemini Voice Assistant -> Native Google Workspace extensions (`@Gmail`, `@Google Tasks`, `@Google Calendar`).
   - **The `#jarvis` Voice Bridge:** Voice commands prefixed with `#jarvis` added to Google Tasks are polled by n8n every 5 minutes, parsed by Gemini Flash, scheduled/executed on the server, and reported back via Discord/WhatsApp.
   - **HyperOS Constraint:** Never run background daemons on the phone (PowerKeeper kills them). All crons/timers run on the OVH VPS; notifications go through FCM-backed channels (Discord / WhatsApp).
2. **Aggregated Sources:**
   - **Primary Gmail (`victorgiroud4@gmail.com`):** Daily analysis, internship search filtering (`stage`, `alternance`, `candidature`, `entretien`), automatic action extraction into Google Tasks.
   - **School Microsoft 365 / Outlook:** Integrated via IMAP (`outlook.office365.com:993`), OAuth2, or automatic forwarding (`+ecole` alias) into the unified morning briefing.
   - **Major/Promo Discord Server (No Admin Rights):** Daily digest of announcements/deadlines using `DiscordChatExporter` CLI (run locally or via residential proxy -> pushed to n8n webhook to bypass Cloudflare datacenter IP blocks on OVH) or Discord Announcement Channel following.
3. **Clinical ADHD Guardrails (Must be enforced in all prompts & UI):**
   - **Point of Performance (Barkley):** Push actionable notifications (max 4 lines) instead of waiting for the user to open a dashboard.
   - **Implementation Intentions (Gollwitzer):** Format daily priorities strictly as *"Si [Déclencheur concret], alors [Micro-action physique de < 2 min]"*.
   - **Zero Shame / Night Amnesty (Sirois & Pychyl):** No red badges, broken streaks, or guilt-tripping language. A 3:00 AM cron silently moves unfinished daily tasks into a secondary "Parking / Réservoir" Google Tasks list.
   - **Ableton Live Sandbox Protocol (Peak-End Rule):** Prevent post-hyperfocus perfectionism aversion. Frame music sessions strictly as 45-minute timed sandboxes ("Sound Design" OR "Arrangement"). At 45 minutes, n8n pings the user to export a 15-second MP3 snippet to close on a tangible win.

---

## 4. Repository Structure
- `.agent` : Master context file for AI coding agents
- `.agents/hooks/pre-commit-secrets.sh` : Blocks API keys/tokens in `workflows/*.json` and source code
- `.agents/skills/adhd-ux-guardrails/SKILL.md` : Clinical ADHD UX & prompt rules
- `.agents/skills/dual-loop-workflow/SKILL.md` : Synchronizing specs and implementation reports between NotebookLM and Antigravity IDE
- `.agents/skills/n8n-workflow-builder/SKILL.md` : n8n JSON & Gemini structured schema rules
- `.agents/skills/sprint1-dynamic-trackers-engine/SKILL.md` : Schema-less Dynamic Trackers & Context Memory Engine (Step 1.1)
- `.agents/skills/vps-infra-deploy/SKILL.md` : OVH VPS, Snap Docker & CloudPanel rules
- `.github/workflows/deploy.yml` : SSH Auto-deploy to OVH VPS on push to `main`
- `docs/achitecture.mmd` : Mermaid system diagram
- `infra/docker-compose.yml` : Production containers (bound strictly to `127.0.0.1`, mounting `n8n_data`, `user_data`, and `workflows`)
- `infra/user_data/` : Private gitignored runtime volume (`trackers_registry.json`, `data_<tracker_id>.json`, `memory_rules.json`)
- `infra/.env.exemple` : Environment template
- `workflows/` : Versioned JSON exports of n8n workflows (`01-voice-agent-core.json`, etc.)
- `scripts/` : Utility scripts (Discord export, backups)
- `backend/` : Sprint 3 REST API (Spring Boot or Laravel)
- `frontend/` : Sprint 3 Jarvis Web Cockpit (Vue.js 3 + Tailwind CSS)

---

## 5. Roadmap & Sprints
- **Sprint 0 (DONE):** Docker n8n infra, CloudPanel SSL reverse proxy (`https://jarvis.victor-giroud.fr/`), isolated `jarvis` Linux user, GitHub Monorepo, and SSH GitHub Actions auto-deploy.
- **Sprint 1 (IN PROGRESS — Vital MVP & Dynamic Trackers Engine):**
  - **Step 1.1 (DONE):** Private `user_data` volume + Schema-less Dynamic Trackers & Context Memory Engine (`workflows/01-voice-agent-core.json` exposing `POST /webhook/voice-command` with the 4 universal Meta-Tools: `create_or_update_tracker_schema`, `mutate_tracker_record`, `query_tracker_records`, `update_memory_rule`).
  - Connect Gemini Flash API & Google Workspace OAuth2 in n8n.
  - Setup private Discord/WhatsApp notification channel.
  - Morning briefing + "Si X, alors Y" plan (`morning_rules` + active trackers).
  - Daily internship email triage + Google Tasks creation.
  - Poll Google Tasks for `#jarvis` voice commands and execute asynchronously.
  - 3:00 AM Night Amnesty silent task rollover to "Parking" list.
- **Sprint 2 (Student & Creative Ecosystem):** School Outlook integration, Promo Discord digest, Ableton 45-min Sandbox timer & MP3 exit ritual, Google Drive "Boîte à vrac".
- **Sprint 3 (Jarvis Cockpit & Analytics):** Fullstack Web Dashboard (Vue.js 3 + Spring Boot/Laravel API), contextual AI chat, 5-second Sport/Nutrition logger, Weekly CBT review agent.

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