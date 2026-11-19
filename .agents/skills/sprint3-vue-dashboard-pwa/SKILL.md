---
name: sprint3-vue-dashboard-pwa
description: Implement Sprint 3 (Step 3.2) — Dynamic Vue.js 3 + Tailwind CSS Cockpit and installable Android PWA (frontend/) driven dynamically by Laravel API trackers, memory rules, and shared Jarvis chat, served on https://jarvis-dashboard.victor-giroud.fr/.
---
# Sprint 3 (Step 3.2) — Dynamic Vue.js 3 Dashboard & Mobile PWA (`frontend/`)

## 1. Architectural Objectives
1. **Single Responsive Codebase (Desktop Cockpit + Android PWA):** Build `frontend/` using **Vue.js 3 (Composition API + `<script setup>`)**, **Vite**, and **Tailwind CSS**, served in production by a lightweight **Nginx Alpine** container (~15–25 MB RAM) on `127.0.0.1:5680:80` behind CloudPanel reverse proxy `https://jarvis-dashboard.victor-giroud.fr/`.
2. **100% Schema-Driven Dynamic Rendering (`trackers_registry.json` via API):** Never hardcode tracker names or domain logic in Vue components. Fetch `GET /api/trackers` and `GET /api/trackers/{id}` and dynamically render:
   - `CYCLE_STATE_MACHINE` cards (current step, `Advance` / `Skip` actions calling `POST /api/trackers/{id}/cycle`).
   - `COLLECTION_TABLE` & `ACTIVITY_LOG` views (filterable records, quick create form calling `POST /api/trackers/{id}/records`, and delete action calling `DELETE /api/trackers/{id}/records/{recordId}`).
3. **Low-Friction Executive UX & Observability:**
   - Crisp, direct executive chief-of-staff copy in French (strictly no patronizing or soft/coddling phrases).
   - Shared chat view (`GET /api/jarvis/chat/history` + `POST /api/jarvis/chat`), morning briefing manual trigger (`POST /api/jarvis/briefing/trigger`), memory rules manager (`GET /api/memory`, `POST /api/memory/rules`, `DELETE /api/memory/rules/{id}`), and a lightweight LLMOps/latency/workflow status panel.
4. **Installable PWA & 1-Tap Voice Shortcut:**
   - Include `public/manifest.webmanifest` and a Service Worker so Android (HyperOS / Chrome) can install `Jarvis` on the home screen with a shortcut (`?view=voice&autostart=true`) that opens the voice interface and starts microphone speech recognition immediately.
5. **Public Repo Hygiene:** Zero hardcoded credentials, tokens, or personal emails in `frontend/`.

---

## 2. Infrastructure, Docker & CI/CD Updates

### A. Container (`frontend/Dockerfile`, `frontend/nginx.conf` & `infra/docker-compose.yml`)
- Multi-stage build: `node:22-alpine` (`npm ci && npm run build`) -> `nginx:1.27-alpine` serving `/usr/share/nginx/html` with SPA fallback (`try_files $uri $uri/ /index.html;`) and `VITE_API_BASE_URL` passed cleanly at build time.
- Add service `jarvis-dashboard` in `infra/docker-compose.yml`:
  - Build context: `../frontend`
  - Build args: `VITE_API_BASE_URL: ${BACKEND_APP_URL:-https://jarvis-api.victor-giroud.fr}`
  - Port binding strictly localhost: `127.0.0.1:5680:80` (for CloudPanel reverse proxy `https://jarvis-dashboard.victor-giroud.fr`)
  - Depends on `jarvis-backend`.

### B. `.github/workflows/deploy.yml`, `.gitignore`, `AGENTS.md`, `README.md` & `docs/achitecture.mmd`
- Ensure `docker compose` rebuilds `jarvis-dashboard` on push to `main` and runs a local health check against `http://127.0.0.1:5680/`.
- Update `.gitignore` for `frontend/node_modules/` and `frontend/dist/`.
- Document `jarvis-dashboard` (`5680:80` -> `https://jarvis-dashboard.victor-giroud.fr`) in `AGENTS.md`, `README.md`, and `docs/achitecture.mmd`.

---

## 3. Frontend Architecture & Views (`frontend/src/`)

### A. API Client & Auth (`src/services/api.js`, `src/stores/auth.js`)
- Store the Sanctum Bearer token in `localStorage` (`jarvis_sanctum_token`), attach `Authorization: Bearer <token>` and `Accept: application/json` automatically, and redirect to the login screen on `401`.
- Clean login form (`POST /api/auth/login`) + logout (`POST /api/auth/logout`).

### B. Views & Dynamic Components
1. **Overview / Cockpit (`/`)**:
   - Summary cards for active trackers, quick "Lancer le briefing" button (`POST /api/jarvis/briefing/trigger`), and a compact **Observabilité / LLMOps** card displaying API health status, active trackers count, memory rules count, and recent chat/action response latency (`ms`).
2. **Dynamic Trackers (`src/components/DynamicTrackerCard.vue`, `src/components/DynamicTrackerDetail.vue`)**:
   - Dynamically adapts UI controls to `tracker.type` (`CYCLE_STATE_MACHINE`, `COLLECTION_TABLE`, `ACTIVITY_LOG`) and `tracker.fields_schema` without hardcoded tracker IDs.
3. **Shared Chat & Voice (`src/views/ChatVoiceView.vue`)**:
   - Displays persistent history from `GET /api/jarvis/chat/history` and sends messages via `POST /api/jarvis/chat`.
   - Includes a Web Speech API (`SpeechRecognition` / `webkitSpeechRecognition`) mic button with `?autostart=true` query support from the PWA shortcut, plus optional browser `speechSynthesis` read-aloud toggle.
4. **Memory Rules (`src/views/MemoryRulesView.vue`)**:
   - Lists `morning_rules` and `custom_scopes` from `GET /api/memory`, allows creating a new rule (`POST /api/memory/rules`) or deleting an existing one (`DELETE /api/memory/rules/{ruleId}`).

---

## 4. Acceptance Criteria & Handover
1. Scaffold `frontend/` (Vue.js 3 + Vite + Tailwind CSS + PWA manifest/service worker), `frontend/Dockerfile`, `frontend/nginx.conf`, and update `infra/docker-compose.yml`, `.github/workflows/deploy.yml`, `.gitignore`, `AGENTS.md`, `README.md`, and `docs/achitecture.mmd`.
2. Run `npm run build` inside `frontend/` to verify zero TypeScript/Vite/Tailwind build errors.
3. Run `./.agents/hooks/pre-commit-secrets.sh` to confirm 0 leaked secrets and valid workflow JSONs.
4. Output the standard `IMPLEMENTATION REPORT` block for Loop 1 synchronization.