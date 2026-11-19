---
name: sprint3-laravel-api-gateway
description: Implement Sprint 3 (Step 3.1) — Lightweight PHP 8.3 / Laravel 11 REST API Gateway (backend/) bridging private user_data JSON trackers/memory and internal n8n webhooks, secured with Sanctum and deployed via Docker + CloudPanel.
---
# Sprint 3 (Step 3.1) — Laravel 11 REST API Gateway (`backend/`)

## 1. Architectural Objectives
1. **Low-RAM Production Footprint (< 80 MB idle):** Build a clean **Laravel 11 (PHP 8.3)** API-only backend in `backend/` using **SQLite** (for Sanctum users/tokens — zero extra database container) and PHP-FPM `ondemand` mode.
2. **Unified Data & Orchestration Gateway:**
   - Directly reads/writes `/var/www/user_data/` (mounted from `/home/jarvis/app/infra/user_data/` with shared `1000:1000` UID and `LOCK_EX` atomic file writes to avoid race conditions with `jarvis-n8n`).
   - Proxies high-level AI actions (voice commands, persistent chat, morning briefing trigger) to `jarvis-n8n` over the internal Docker network (`http://jarvis-n8n:5678/webhook/...`).
3. **Portfolio-Grade Engineering:** Clean Service/Controller separation (`TrackerStorageService`, `MemoryRuleService`, `N8nGatewayService`), FormRequest validation, JSON API Resources, Laravel Sanctum authentication, and PHPUnit feature tests (with `Http::fake()` for n8n webhooks).
4. **Public Repo Hygiene:** Zero hardcoded secrets, zero personal use-cases or emails in code. All credentials come from `.env`.

---

## 2. Infrastructure & Docker Setup

### A. Container (`backend/Dockerfile` & `infra/docker-compose.yml`)
- Create a lightweight container (e.g., `serversideup/php:8.3-fpm-nginx-alpine` or `php:8.3-fpm-alpine` + Nginx) configured to run as **UID/GID `1000:1000`** so file ownership in `infra/user_data/` stays 100% compatible with `jarvis-n8n`.
- Configure PHP-FPM with `pm = ondemand`, `pm.max_children = 5`, `pm.process_idle_timeout = 10s`.
- Add service `jarvis-backend` to `infra/docker-compose.yml`:
  - Build context: `../backend`
  - Port binding strictly localhost: `127.0.0.1:5679:8080` (for CloudPanel Nginx reverse proxy `https://jarvis-api.victor-giroud.fr` -> `http://127.0.0.1:5679`)
  - Volumes:
    - `./user_data:/var/www/user_data`
    - `./backend_data:/var/www/storage/app/sqlite` (persists `database.sqlite` across rebuilds)
  - Environment: `APP_ENV`, `APP_KEY`, `APP_URL`, `FRONTEND_URL`, `JARVIS_OWNER_EMAIL`, `JARVIS_OWNER_PASSWORD`, `N8N_INTERNAL_URL=http://jarvis-n8n:5678`, `USER_DATA_PATH=/var/www/user_data`.
  - Depends on `jarvis-n8n`.

### B. `.gitignore`, `infra/.env.exemple` & `.github/workflows/deploy.yml`
1. Update `.gitignore` to ignore `infra/backend_data/`, `backend/.env`, `backend/vendor/`, and `backend/database/*.sqlite`.
2. Add sanitized placeholders to `infra/.env.exemple`:

    BACKEND_APP_KEY=base64:replace_with_32_byte_key
    BACKEND_APP_URL=https://jarvis-api.victor-giroud.fr
    FRONTEND_URL=https://jarvis-dashboard.victor-giroud.fr
    JARVIS_OWNER_EMAIL=owner@example.com
    JARVIS_OWNER_PASSWORD=change_me_strong_password

3. Update `.github/workflows/deploy.yml`:
   - Create `/home/jarvis/app/infra/backend_data` with `1000:1000` ownership.
   - After `docker compose ... up -d --build --remove-orphans`, run database migrations & idempotent owner user seeding inside `jarvis-backend` (`php artisan migrate --force && php artisan jarvis:seed-owner`).

---

## 3. Laravel 11 API Specification (`backend/`)

### A. Authentication (Laravel Sanctum)
- Artisan command `jarvis:seed-owner`: idempotently creates/updates the single owner account in SQLite from `JARVIS_OWNER_EMAIL` & `JARVIS_OWNER_PASSWORD`.
- `GET /api/health` (public): returns `{ "status": "ok", "user_data_readable": true, "timestamp": "ISO-8601" }`.
- `POST /api/auth/login` (public): validates email/password, returns `{ "token": "<sanctum_plain_text_token>", "user": { "name", "email" } }`.
- `POST /api/auth/logout` (auth:sanctum): revokes current token.
- `GET /api/auth/me` (auth:sanctum): returns current authenticated user.

### B. Dynamic Trackers Endpoints (`auth:sanctum`)
Backed by `TrackerStorageService` (strict `snake_case` ID validation to prevent path traversal, atomic `file_put_contents(..., LOCK_EX)`):
- `GET /api/trackers`: Reads `trackers_registry.json`, enriches each tracker with `record_count` (from `data_<id>.json`) and `current_step` (for `CYCLE_STATE_MACHINE`).
- `GET /api/trackers/{trackerId}`: Returns tracker schema + active records from `data_<trackerId>.json` (optional query `?include_archive=true` appends `archive_<trackerId>.json`).
- `POST /api/trackers/{trackerId}/records`: Inserts a new record (generates UUIDv4 `id`, `created_at`, `updated_at`) into `data_<trackerId>.json`.
- `PATCH /api/trackers/{trackerId}/records/{recordId}`: Partially updates an existing record and refreshes `updated_at`.
- `DELETE /api/trackers/{trackerId}/records/{recordId}`: Deletes a record by ID.
- `POST /api/trackers/{trackerId}/cycle`: Body `{ "action": "advance" | "skip" | "set", "target_index"?: int }`:
  - `advance`: increments `current_index` modulo `steps.length`, updates `last_updated` to today, logs entry.
  - `skip`: keeps `current_index` unchanged, updates `last_updated` to today, AND writes a volatile rule expiring tomorrow in `memory_rules.json` (`custom_scopes.cycle_overrides`) so `02-morning-briefing` does not advance past the skipped session.
  - `set`: sets `current_index` to `target_index` (validated within bounds).

### C. Memory Rules Endpoints (`auth:sanctum`)
Backed by `MemoryRuleService` (auto-purges expired volatile rules where `expires_at < today` on read):
- `GET /api/memory`: Returns `morning_rules` and `custom_scopes`.
- `POST /api/memory/rules`: Body `{ "scope": "morning_rules" | "<custom_scope>", "instruction": "string", "volatile"?: bool, "expires_at"?: "YYYY-MM-DD" }`.
- `DELETE /api/memory/rules/{ruleId}`: Removes rule by ID from `morning_rules` or any `custom_scopes` entry.

### D. n8n Orchestrator Bridge Endpoints (`auth:sanctum`)
Backed by `N8nGatewayService` (`Http::timeout(45)` calling `N8N_INTERNAL_URL`):
- `POST /api/jarvis/command`: Body `{ "command": "string", "notify_discord"?: bool }` -> proxies to `POST /webhook/voice-command` (default `notify_discord: false`).
- `GET /api/jarvis/chat/history`: Reads `discord_chat_history.json` (`messages` array) so the web dashboard and Discord `#chatbot` share the exact same persistent conversation!
- `POST /api/jarvis/chat`: Body `{ "text": "string" }` -> proxies to `POST /webhook/discord-chat` (`channel_id: "web-dashboard"`, `author: "owner"`) and returns `{ "reply": "..." }`.
- `POST /api/jarvis/briefing/trigger`: Proxies to `POST /webhook/morning-briefing` and returns the generated briefing JSON.

### E. CORS Configuration
- Configure `config/cors.php` to allow `FRONTEND_URL` and `http://localhost:5173` (Vite dev server) with Bearer token headers (`Authorization`, `Content-Type`, `Accept`).

---

## 4. Acceptance Criteria & Handover
1. Scaffold `backend/` (Laravel 11, PHP 8.3, Sanctum, SQLite), `backend/Dockerfile`, and update `infra/docker-compose.yml`, `infra/.env.exemple`, `.gitignore`, `.github/workflows/deploy.yml`, `AGENTS.md`, `README.md`, and `docs/achitecture.mmd`.
2. Write PHPUnit feature tests covering: healthcheck, Sanctum login/auth guard, tracker CRUD + cycle advance/skip (including volatile override rule), memory rules CRUD, and mocked n8n proxy calls (`php artisan test` must pass 100%).
3. Run `.agents/hooks/pre-commit-secrets.sh` to verify 0 hardcoded secrets and valid workflow JSONs.
4. Output the standard `IMPLEMENTATION REPORT` block for Loop 1 synchronization.