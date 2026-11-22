---
name: sprint4-dashboard-refonte
description: Refactor the Vue.js 3 Dashboard & PWA (frontend/) and Laravel 11 Backend (backend/) — Remove Gemini Live completely, add a Pastel Cream light theme toggle, streamline header/voice/memory views, centralize secondary tools into a Debug view, and put dynamic trackers front and center.
---
# Sprint 4 (Priority 1) — Dashboard Refonte, Pastel Cream Light Theme & Gemini Live Removal

## 1. Architectural Objectives
1. **Remove Gemini Live Completely (Cost & Simplicity):** Remove all Gemini Multimodal Live WebSocket code, ephemeral token issuance, and live tool bridge endpoints from both `frontend/` and `backend/`. Keep `05-weekly-review` and all existing n8n workflows intact.
2. **Trackers-First Home Screen (`/`):** Open directly onto dynamic, schema-driven trackers (`trackers_registry.json`) with maximum visual breathing room. Move all secondary stats, latency telemetry, health indicators, and manual briefing triggers out of the main screens into a dedicated **Debug** menu/view.
3. **Pastel Cream Light Theme + Theme Switcher:** Add a refined **Light Theme (Pastel & Cream palette)** alongside the existing dark theme, toggleable from the top header bar and persisted in `localStorage`.
4. **Ultra-Lean Voice Mode:** Remove chat history rendering and text input from the voice view (written chat happens in Discord `#chatbot`) to minimize RAM usage and visual clutter. Keep 1-tap voice capture (`/?view=voice&autostart=true`), Markdown rendering for the latest reply, and Markdown stripping before TTS read-aloud.
5. **Clear Read/Delete-Only Memory View:** Reorganize `MemoryRulesView.vue` into clearly separated sections (`morning_rules` vs `custom_scopes`), remove the "Nouvelle règle contextuelle" form, and keep consultation + deletion only.
6. **Public Repo Hygiene & Executive UX:** Zero hardcoded secrets or personal data. Keep direct, concise French executive copy (zero patronizing language, zero streaks/shame indicators).

---

## 2. Backend Cleanup — Remove Gemini Live (`backend/` & `infra/`)

### A. Delete Gemini Live Files & Endpoints
- Delete `backend/app/Services/GeminiLiveService.php`.
- Delete `backend/app/Http/Requests/JarvisLiveToolRequest.php`.
- In `backend/routes/api.php`: remove `POST /api/jarvis/live/session` and `POST /api/jarvis/live/tool`.
- In `backend/app/Http/Controllers/JarvisController.php`: remove the Live session and Live tool handler methods and unused imports.
- In `backend/config/jarvis.php` and `backend/.env.example`: remove Gemini Live-only configuration keys (`gemini_live_*`, `GEMINI_API_KEY` if no other Laravel service uses it).
- In `infra/docker-compose.yml`: remove `GEMINI_API_KEY` from `jarvis-backend` `environment:` (do NOT remove anything needed by `jarvis-n8n`).
- Update `backend/tests/Feature/JarvisApiGatewayTest.php`: remove Gemini Live test cases and verify `php artisan test` passes 100%.

### B. Documentation Sync
- Update `AGENTS.md`, `README.md`, and `docs/achitecture.mmd` to reflect the removal of Gemini Live while keeping the rest of the architecture (including `05-weekly-review`) documented accurately.

---

## 3. Frontend — Theme System (Pastel Cream Light Theme + Dark Mode)

### A. Theme Management (`frontend/src/composables/useTheme.js` or `src/stores/theme.js`)
- Support two modes: `'light'` (Pastel Cream) and `'dark'` (existing dark cockpit), persisted in `localStorage` under `jarvis_theme`.
- Apply `.dark` / `data-theme="light" | "dark"` on `document.documentElement` before/on mount to prevent flash of wrong theme.
- Dynamically update `<meta name="theme-color" content="...">` so the Android PWA status bar matches the active theme (`#FAF6F0` in light mode, dark graphite in dark mode).
- Use smooth CSS transitions (or `document.startViewTransition` when available) when toggling themes.

### B. Pastel & Cream Light Palette (`frontend/src/style.css` & `tailwind.config.js`)
Define semantic CSS variables so every component adapts cleanly between themes with WCAG AA+ contrast:
- **Canvas Background:** Warm Cream (`#FAF6F0` / `#F7F1E5`)
- **Card / Surface:** Soft Ivory (`#FFFDF9`) with subtle warm border (`#E8DFD1`) and soft elevation shadow
- **Secondary / Subtle Surface:** Warm Sand (`#F3ECE1`)
- **Primary Text:** Deep Espresso Charcoal (`#2B2623`)
- **Muted Text:** Warm Taupe (`#6E655D`)
- **Pastel Accents:**
  - Primary / Action: Soft Terracotta / Peach (`#DF8A6A`, soft tint `#F8E4DA`, hover `#CE7858`)
  - Success / Cycle Active: Sage Pastel (`#7FA892`, soft tint `#E3EFE8`)
  - Scopes / Tags / Volatile: Dusty Lavender (`#9D8EC2`, soft tint `#EEEAF7`)
  - Warning / Highlight: Soft Butter (`#D9B45A`, soft tint `#F9F0D6`)
  - Destructive (Delete): Muted Rose (`#C96D6D`, soft tint `#F8E3E3`)

---

## 4. Frontend — Layout, Header Bar & Global Cleanup (`frontend/src/App.vue`)

1. **Header Bar Cleanup:**
   - **Remove the « Dictée » button** from the header bar completely.
   - **Compact « Refresh » icon button:** Remove all large « Refresh » buttons from individual views and place a single compact icon-only Refresh button in the top header bar (spinning subtly while refreshing, disabled during pending state).
   - **Compact Theme Toggle:** Place a compact Sun/Moon icon toggle next to Refresh in the top header bar.
   - Ensure all header icon buttons maintain a generous mobile touch target (`min-w-[44px] min-h-[44px]` via padding/hit-slop) and respect `env(safe-area-inset-top)` / `env(safe-area-inset-bottom)` for standalone Android PWA mode.
2. **Remove « Briefing » Buttons Across All Main Views:**
   - Remove the large « Briefing » / « Lancer le briefing » button from the Overview and all other primary screens. Move it exclusively into the **Debug** view.
3. **Navigation Tabs:**
   - Keep primary navigation clean and focused: **Trackers** (default `/`), **Vocal**, **Mémoire**, and a secondary **Debug** entry (e.g., subtle gear/terminal icon or secondary tab).

---

## 5. Frontend — View-by-View Specifications

### A. Home / Trackers View (`/` — `App.vue` / `DynamicTrackerCard.vue` / `DynamicTrackerDetail.vue`)
- **Trackers Front & Center:** Immediately display the auto-generated trackers grid (`CYCLE_STATE_MACHINE`, `COLLECTION_TABLE`, `ACTIVITY_LOG`) as the hero content of the home screen.
- **Remove Clutter:**
  - Remove all "instructions en direct" / live helper banners.
  - Remove summary KPI cards ("Trackers actifs", memory count, API health status, and response latency `ms`) from the home screen — move them all to **Debug**.
- **Polish:**
  - Use **Skeleton Loaders** matching tracker card dimensions during initial load instead of generic spinners.
  - Apply `tabular-nums` to all numeric fields, dates, and table columns, and `text-wrap: balance` to tracker titles.
  - Ensure all action buttons (`Advance`, `Skip`, record mutations) have disabled/pending states to prevent double submissions.

### B. Lean Voice View (`frontend/src/views/ChatVoiceView.vue` -> lightweight voice-only view)
- **Delete `frontend/src/components/LiveVoiceSession.vue`** and remove all WebSocket/PCM/Gemini Live toggles and references from `ChatVoiceView.vue`, `api.js`, and `sw.js`.
- **Remove Message History:** Do NOT fetch (`GET /api/jarvis/chat/history`) or render a scrollable chat history list. Keep memory footprint minimal by holding only the **current exchange** (latest user transcript + latest Jarvis reply).
- **Remove Text Input Bar:** Delete the `<input>` / `<textarea>` and text submit button completely (written messages are handled via Discord).
- **Keep Focused Voice UX:**
  - Large, tactile central microphone button (`SpeechRecognition` / `webkitSpeechRecognition`) preserving PWA shortcut `/?view=voice&autostart=true`.
  - Clear visual state (`Prêt`, `Écoute en cours…`, `Traitement…`) with clean interruptible CSS transitions.
  - **Markdown Display + Clean TTS:** Render the latest Jarvis reply as formatted Markdown (safe, sanitized HTML via a lightweight parser like `marked` + sanitization or minimal safe Markdown renderer), AND strip Markdown syntax (`**`, `*`, `#`, `` ` ``, list bullets, links) into plain spoken text before passing it to `window.speechSynthesis` so TTS never reads symbols aloud.

### C. Reorganized Memory View (`frontend/src/views/MemoryRulesView.vue`)
- **Remove « Nouvelle règle contextuelle »:** Delete the creation/editing form completely.
- **Read & Delete Only:** Keep only consultation (`GET /api/memory`) and deletion (`DELETE /api/memory/rules/{id}`) with a subtle non-blocking toast notification on deletion (avoid intrusive blocking `confirm()` dialogs where possible, or use a clean inline 2-step confirm / toast feedback).
- **Clear Dedicated Sections:**
  1. **Règles permanentes du briefing (`morning_rules`)**: Dedicated section with clear header, count badge (`tabular-nums`), and clean list items with delete action.
  2. **Scopes contextuels (`custom_scopes`)**: Dedicated section visually separated from morning rules, grouping rules cleanly by scope name (distinct cards/subsections per scope). Display clear pastel badges for `volatile` status and `expires_at` date (`tabular-nums`).
- Use matching skeleton placeholders while loading.

### D. New Debug View / Menu (`frontend/src/views/DebugView.vue`)
Centralize all non-1-click operational controls and diagnostics removed from the main screens:
- **Actions manuelles:** Button to trigger the Morning Briefing (`POST /api/jarvis/briefing/trigger`) with pending state and inline status/result feedback.
- **Santé & Système (`Health`):** Display `/api/health` status, `user_data` lock status, API base URL status.
- **Métriques & Observabilité:** Active trackers count, memory rules count (morning + custom scopes), and rolling API/n8n response latency in `ms` (`tabular-nums`).

---

## 6. Acceptance Criteria & Handover
1. Remove Gemini Live cleanly across `backend/`, `frontend/`, `infra/docker-compose.yml`, and docs (`AGENTS.md`, `README.md`, `docs/achitecture.mmd`).
2. Run `php artisan test` inside `backend/` — 100% passing.
3. Run `npm run build` inside `frontend/` — 0 errors, clean bundle size.
4. Run `./.agents/hooks/pre-commit-secrets.sh` — 0 leaked secrets, valid JSON across all `workflows/*.json`.
5. Output the standard `IMPLEMENTATION REPORT` block for Loop 1 synchronization.