---
name: sprint4-sandboxed-js-views
description: Upgrade Custom Tracker Views to full HTML + CSS + JavaScript sandboxed micro-apps (<iframe sandbox="allow-scripts">) powered by a generic window.jarvis postMessage SDK, with server-side JS syntax validation in n8n and removal of default cycle action buttons.
---
# Sprint 4 (Priority 2.2) — Sandboxed HTML/CSS/JS Custom Views & Generic `window.jarvis` SDK

## 1. Architectural Objectives
1. **Unlimited Voice-Driven UI Customization:** Replace the rigid Mustache/Shadow DOM template renderer with a **Sandboxed Micro-App Engine** (`<iframe sandbox="allow-scripts">` without `allow-same-origin`) where Jarvis can write real **HTML + CSS + JavaScript** for any tracker (tabs, daily aggregations/totals, search/filters, inline record details, 1-click record duplication, SVG charts) without ever hardcoding domain-specific features in the repository.
2. **Zero-Trust Sandbox Security:**
   - The iframe MUST use `sandbox="allow-scripts"` ONLY (NEVER `allow-same-origin`, NEVER `allow-top-navigation`), so generated JS has zero access to `localStorage`, cookies, Sanctum tokens, or the parent Vue DOM.
   - Enforce a strict `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:;">` inside `srcdoc` so the iframe cannot make external `fetch()` calls or load external scripts/assets.
3. **Universal `window.jarvis` SDK (`postMessage` Bridge):** Provide a clean, domain-agnostic JS API inside the iframe to read full active tracker records and perform CRUD / cycle mutations through the authenticated parent Vue app.
4. **Live Theme Inheritance & Auto-Resize:** Sync all semantic CSS variables (`Pastel Cream Light` & `Graphite Dark`) into the iframe dynamically on theme toggle, and auto-size iframe height via `ResizeObserver`.
5. **Default Cycle Card Cleanup:** Remove default action buttons (`Avancer`, `Maintenir / Reporter`) from the generic `CYCLE_STATE_MACHINE` card view so cycle buttons only appear when explicitly requested in a custom view.

---

## 2. Data Contract (`trackers_registry.json`)

Extend `custom_view` on each tracker with a `js` field (backward-compatible if `js` is absent or empty):

    "custom_view": {
      "enabled": true,
      "html": "<div id=\"app\"></div>",
      "css": ".row { ... }",
      "js": "function render() { ... } render(); jarvis.onUpdate(render);",
      "updated_at": "ISO-8601"
    }

---

## 3. Backend Updates (`backend/`)

1. **Sanitizer (`backend/app/Services/CustomViewSanitizer.php`):**
   - Update `sanitize(?array $customView)` to accept and sanitize `html`, `css`, and `js`.
   - Strip external resource tags/directives (`<script src=...>`, `<iframe>`, `<object>`, `<embed>`, `<link>`, `<base>`, CSS `@import`, `url(http...)`), while allowing safe inline `html`, `css`, and `js` (which execute strictly inside the isolated `sandbox="allow-scripts"` CSP iframe).
   - Enforce generous but safe size caps: HTML <= 24 KB, CSS <= 12 KB, JS <= 24 KB.
2. **Full Active Records for Custom Views (`backend/app/Services/TrackerStorageService.php`):**
   - In `listTrackers()`, continue filtering out soft-deleted entries (`deleted === true` or `"SUPPRIMÉ"` / `"[SUPPRIMÉ]"`), and increase the attached `recent_records` cap (e.g., up to 200 active records, newest first) so JS custom views can accurately compute daily totals, historical stats, and search across full history without N+1 requests.
   - Ensure `updateCustomView()` persists `{ enabled, html, css, js, updated_at }` atomically with `LOCK_EX`.
3. **Controller & Validation (`backend/app/Http/Controllers/TrackerController.php`):**
   - Update `PATCH /api/trackers/{trackerId}/view` validation rules to accept optional `js` (`nullable|string|max:24576`).
4. **Tests (`backend/tests/Feature/JarvisApiGatewayTest.php`):**
   - Update/add feature tests verifying `PATCH /api/trackers/{id}/view` stores `html`, `css`, and `js`, strips external `<iframe`/`<script src=`, and returns `custom_view` cleanly in `GET /api/trackers`.

---

## 4. Frontend Updates (`frontend/src/`)

### A. Sandboxed Iframe Component (`frontend/src/components/CustomTrackerView.vue`)
Replace the Shadow DOM / Mustache implementation with a sandboxed `<iframe sandbox="allow-scripts">`:
1. **Strict `srcdoc` Bootstrap:**
   - Include `<meta charset="UTF-8">`, `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:;">`.
   - Inject a base CSS reset (`*, *::before, *::after { box-sizing: border-box; } body { margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; color: var(--text-primary); background: transparent; overflow-x: hidden; font-variant-numeric: tabular-nums; }`) + live theme CSS variables (`--bg-canvas`, `--bg-surface`, `--bg-subtle`, `--border-subtle`, `--text-primary`, `--text-muted`, `--accent-primary`, `--accent-soft`, `--pastel-sage`, `--pastel-sage-soft`, `--pastel-lavender`, `--pastel-lavender-soft`, `--pastel-butter`, `--pastel-butter-soft`, `--pastel-rose`, `--pastel-rose-soft`) + `custom_view.css`.
   - Inject the `window.jarvis` SDK script before running the agent's `custom_view.js`:
     - `jarvis.tracker`: tracker metadata (`id`, `display_name`, `type`, `cycle_config`, `fields_schema`, `record_count`)
     - `jarvis.records`: array of all active records (newest first)
     - `jarvis.theme`: `'light' | 'dark'`
     - `jarvis.today`: current local date `YYYY-MM-DD`
     - `jarvis.addRecord(data)` -> returns Promise resolving to created record
     - `jarvis.updateRecord(recordId, data)` -> returns Promise resolving to updated record
     - `jarvis.deleteRecord(recordId)` -> returns Promise resolving to `{ deleted: true }`
     - `jarvis.mutateCycle(operation, payload)` -> returns Promise resolving to updated tracker (`operation`: `'advance_cycle' | 'skip_or_shift_cycle'`)
     - `jarvis.openDetail(recordId)` -> opens `DynamicTrackerDetail.vue` in parent
     - `jarvis.toast(message)` -> shows a non-blocking toast in parent
     - `jarvis.onUpdate(fn)` -> registers a callback invoked whenever parent pushes updated `tracker`, `records`, or `theme` via `postMessage`
   - Include a `ResizeObserver` on `document.body` that posts `{ type: 'jarvis:resize', viewId, height }` to the parent so the iframe adjusts its height smoothly (`min-height: 80px`, `max-height: 640px`).
   - Attach `window.onerror` and `window.onunhandledrejection` handlers that post `{ type: 'jarvis:error', viewId, message }` to the parent.
2. **Parent `postMessage` Bridge:**
   - Generate a unique `viewId` per `CustomTrackerView` instance to strictly match incoming messages from that specific iframe (`event.source === iframeRef.value?.contentWindow`).
   - Handle `jarvis:rpc` requests (`addRecord`, `updateRecord`, `deleteRecord`, `mutateCycle`, `openDetail`, `toast`), execute the authenticated Laravel API call via `api.js`, refresh tracker data, and reply with `{ type: 'jarvis:rpc-result', requestId, ok, data, error }`.
   - Watch `themeStore.theme` and props (`tracker`, `records`) and push live CSS variables + `{ type: 'jarvis:update', tracker, records, theme }` via `postMessage` without reloading the iframe.
   - If the iframe reports a runtime `jarvis:error`, display a compact, non-blocking inline error banner with the error message, a **« Copier l'erreur »** button (to paste back to Jarvis for instant fixing), and a **« Vue standard »** fallback button.

### B. Card & App Integration (`DynamicTrackerCard.vue`, `App.vue`, `public/sw.js`)
- **Remove Default Cycle Action Buttons:** In `DynamicTrackerCard.vue`, remove the default `Avancer` and `Maintenir / Reporter` buttons from the generic `CYCLE_STATE_MACHINE` card view (keep the informational cycle display only; buttons can be added on demand via custom views using `jarvis.mutateCycle(...)`).
- Wire `CustomTrackerView.vue` events (`refresh-requested`, `open-detail`, `toast`) up to `App.vue` so mutations triggered inside a custom JS view immediately refresh the tracker state and show a toast.
- Delete obsolete `frontend/src/utils/customViewRenderer.js` if no longer referenced.
- Bump Service Worker cache version in `frontend/public/sw.js`.

---

## 5. n8n Voice Agent Updates (`workflows/01-voice-agent-core.json`)

### A. Upgrade `update_tracker_custom_view` Code Tool
- Accept JSON payload: `{ "tracker_id": "string", "action": "set | reset", "html": "string", "css": "string", "js": "string" }`.
- **Automatic JS Syntax Validation (Self-Correcting Loop):**
  - If `js` is provided, validate syntax before saving using `new Function('jarvis', js)` inside a `try { ... } catch (err) { ... }`.
  - If a `SyntaxError` occurs, do NOT save — return `{ status: "error", error: "Erreur de syntaxe JS : " + err.message + ". Corrige le JS et rappelle l'outil.", available_fields }` so Gemini fixes it immediately in the same turn.
- Strip external `<script src=...>`, `<iframe>`, `<link>`, and CSS `@import` from `html`/`css`.
- Always return `available_fields` (exact field names from `tracker.fields_schema`) in the tool response.
- Save `{ enabled: true, html, css, js, updated_at }` into `trackers_registry.json`.

### B. Update `Load Runtime Context` (`custom_view_guidelines`)
Replace the old Mustache instructions in `customViewGuidelines` with crisp, concise instructions for the **HTML + CSS + JS + `window.jarvis` SDK**:
- State clearly:
  - You write real **HTML**, **CSS**, and **JavaScript** (NO Mustache `{{...}}` syntax).
  - Never use `fetch()` or external libraries/CDNs (blocked by sandbox CSP).
  - Never duplicate the tracker title already displayed by the parent card header.
  - All soft-deleted records are already filtered out in `jarvis.records`.
  - Use exact field names from `fields_schema` when reading properties on each record in `jarvis.records`.
  - Use semantic CSS variables (`var(--bg-surface)`, `var(--bg-subtle)`, `var(--border-subtle)`, `var(--text-primary)`, `var(--text-muted)`, `var(--accent-primary)`, `var(--pastel-sage)`, `var(--pastel-lavender)`, `var(--pastel-butter)`, `var(--pastel-rose)`).
  - Document the exact `window.jarvis` API (`jarvis.tracker`, `jarvis.records`, `jarvis.today`, `jarvis.theme`, `await jarvis.addRecord(data)`, `await jarvis.updateRecord(id, data)`, `await jarvis.deleteRecord(id)`, `await jarvis.mutateCycle(op, payload)`, `jarvis.openDetail(id)`, `jarvis.toast(msg)`, `jarvis.onUpdate(render)`).
  - Provide a compact 10-line reference pattern showing `function render() { ... } render(); jarvis.onUpdate(render);` and safe DOM rendering.
  - Keep the strict rule: NEVER output raw HTML/CSS/JS in the user-facing text reply; always call `update_tracker_custom_view` and confirm in 1–2 concise French lines.

---

## 6. Acceptance Criteria & Handover
1. Run `php artisan test` in `backend/` — 100% passing.
2. Run `npm run build` in `frontend/` — 0 errors.
3. Run `./.agents/hooks/pre-commit-secrets.sh` — 0 leaked secrets, valid JSON across all workflows.
4. Update `AGENTS.md`, `README.md`, and `docs/ARCHITECTURE_DEEP_DIVE.md`.
5. Output the standard `IMPLEMENTATION REPORT` block for Loop 1 synchronization.