---
name: sprint4-agent-custom-views
description: Implement Sprint 4 (Priority 2) — Agent-generated custom HTML/CSS views per tracker, rendered safely via Shadow DOM with live data templating, theme CSS variable inheritance, and voice-driven creation/editing in n8n.
---
# Sprint 4 (Priority 2) — Agent-Generated Custom Tracker Views (HTML/CSS + Shadow DOM)

## 1. Architectural Objectives
1. **Voice-Driven Custom UI per Tracker:** Allow Jarvis (via `01-voice-agent-core.json`) to generate or update a tailored HTML + CSS widget for any tracker at creation time or on demand by voice (e.g., *"Affiche mon cycle de muscu comme une timeline horizontale"* or *"Mets les devis en attente en avant avec des badges pastel"*).
2. **Strict Isolation & Security (Zero XSS, Zero Layout Bleed):**
   - Render custom HTML/CSS inside a native **Shadow DOM** (`attachShadow({ mode: 'open' })`) so agent-generated CSS can NEVER break the global dashboard layout.
   - Sanitize all HTML/CSS server-side (Laravel) and client-side (Vue) to strip `<script>`, `<iframe>`, `<object>`, `<embed>`, `<link>`, `@import`, inline `on*` event handlers, and `javascript:` URLs.
3. **Automatic Dual-Theme Compatibility:** Expose the dashboard's semantic CSS custom properties (`--bg-surface`, `--bg-subtle`, `--border-subtle`, `--text-primary`, `--text-muted`, `--accent-primary`, `--pastel-sage`, `--pastel-lavender`, `--pastel-butter`, `--pastel-rose`) so Shadow DOM views automatically look native in both **Pastel Cream Light** and **Graphite Dark** modes.
4. **Live Data Binding & Safe Declarative Actions:** Hydrate lightweight mustache-style placeholders (`{{display_name}}`, `{{current_step}}`, `{{count}}`, `{{#each records}}...{{/each}}`) with live tracker data, and support safe declarative buttons (`data-jarvis-action="advance_cycle" | "skip_cycle" | "open_detail"`).
5. **Graceful Fallback:** If a tracker has no `custom_view` (or `enabled: false`), render the standard generic `DynamicTrackerCard.vue` seamlessly.

---

## 2. Data Contract Extension (`trackers_registry.json`)

Extend each tracker object in `/home/node/user_data/trackers_registry.json` with an optional `custom_view` property (backward-compatible with all existing trackers):

    {
      "trackers": {
        "<tracker_id>": {
          "display_name": "string",
          "type": "CYCLE_STATE_MACHINE | COLLECTION_TABLE | ACTIVITY_LOG",
          "include_in_morning_briefing": true,
          "cycle_config": { ... },
          "fields_schema": [ ... ],
          "custom_view": {
            "enabled": true,
            "html": "<div class=\"cv-root\">...</div>",
            "css": ".cv-root { ... }",
            "updated_at": "ISO-8601"
          }
        }
      }
    }

---

## 3. n8n Voice Agent Updates (`workflows/01-voice-agent-core.json`)

### A. Add Meta-Tool `update_tracker_custom_view` (Code Tool Node)
Wire a new `@n8n/n8n-nodes-langchain.toolCode` node (`update_tracker_custom_view`) via `ai_tool` to `Jarvis Voice AI Agent`:
- **Input JSON payload:** `{ "tracker_id": "string", "action": "set | reset", "html": "string", "css": "string" }`.
- **Sanitization inside the tool:** Strip `<script...>...</script>`, `<iframe...>`, `on\w+=` attributes, and `javascript:` URIs before writing to `/home/node/user_data/trackers_registry.json`.
- **Behavior:**
  - If `action === "reset"`: remove `custom_view` (or set `custom_view.enabled = false`) on the target tracker.
  - If `action === "set"`: store `{ enabled: true, html, css, updated_at: nowIso }` on `registry.trackers[tracker_id].custom_view` and persist `trackers_registry.json`.

### B. Optional Support in `create_or_update_tracker_schema`
- Preserve existing `custom_view` when updating a tracker schema (never wipe it accidentally), and allow passing an optional `custom_view: { html, css }` directly at tracker creation time.

### C. System Prompt Guidelines (`Jarvis Voice AI Agent`)
Update `systemMessage` to teach Gemini how to craft clean, compact, theme-aware views when the user asks to customize a tracker's display (or creates a tracker with visual preferences):
- Keep HTML/CSS concise, responsive (mobile-first), and clean.
- Use CSS variables (`var(--text-primary)`, `var(--text-muted)`, `var(--bg-subtle)`, `var(--border-subtle)`, `var(--accent-primary)`, `var(--pastel-sage)`, `var(--pastel-lavender)`, `var(--pastel-butter)`, `var(--pastel-rose)`) instead of hardcoded black/white colors so both Pastel Cream Light and Dark themes work automatically.
- Available template placeholders:
  - Common: `{{display_name}}`, `{{type}}`, `{{count}}`
  - Cycle (`CYCLE_STATE_MACHINE`): `{{current_step}}`, `{{next_step}}`, `{{current_index}}`, `{{total_steps}}`, `{{last_updated}}`, `{{#each steps}}...{{this}}...{{/each}}` (with `{{is_current}}` class helper)
  - Records (`COLLECTION_TABLE` / `ACTIVITY_LOG`): `{{#each records}}...{{field_name}}...{{/each}}` (iterates over the latest 5–8 records, newest first)
- Declarative interactive buttons (no JS needed):
  - `data-jarvis-action="advance_cycle"`
  - `data-jarvis-action="skip_cycle"`
  - `data-jarvis-action="open_detail"`

---

## 4. Backend Updates (`backend/`)

1. **Sanitizer Helper (`backend/app/Services/CustomViewSanitizer.php`):**
   - Strictly strips `<script>`, `<iframe>`, `<object>`, `<embed>`, `<form>`, `<link>`, inline `on*` event attributes, `javascript:` / `data:text/html` URLs, and CSS `@import` / `expression(...)`.
   - Enforces reasonable size caps (e.g., max 16 KB HTML, max 8 KB CSS).
2. **TrackerStorageService (`backend/app/Services/TrackerStorageService.php`):**
   - Include sanitized `custom_view` in tracker responses (`GET /api/trackers` and `GET /api/trackers/{id}`).
   - Add `updateCustomView(string $trackerId, ?array $customView)` using `LOCK_EX` atomic writes.
3. **API Routes (`backend/routes/api.php` & `TrackerController.php`):**
   - `PATCH /api/trackers/{id}/view` (`auth:sanctum`): update/toggle `custom_view` (`enabled`, `html`, `css`).
   - `DELETE /api/trackers/{id}/view` (`auth:sanctum`): reset tracker back to standard generic view.
4. **Tests (`backend/tests/Feature/JarvisApiGatewayTest.php`):**
   - Test `PATCH /api/trackers/{id}/view` (verifying XSS/script stripping), `DELETE /api/trackers/{id}/view`, and `GET /api/trackers` returning `custom_view`.

---

## 5. Frontend Updates (`frontend/src/`)

### A. Template & Sanitization Utility (`frontend/src/utils/customViewRenderer.js`)
- `sanitizeCustomHtml(html)` & `sanitizeCustomCss(css)`: client-side defense-in-depth mirror of backend sanitization (zero external heavy dependencies).
- `hydrateCustomTemplate(html, tracker, records)`:
  - Safely HTML-escapes all injected data values to prevent data-borne XSS.
  - Evaluates `{{#each records}}...{{/each}}` (latest records first) and `{{#each steps}}...{{/each}}` (for cycles, supporting `{{label}}` and `{{is_current}}`).
  - Replaces `{{display_name}}`, `{{count}}`, `{{current_step}}`, `{{next_step}}`, `{{current_index}}`, `{{total_steps}}`, `{{last_updated}}`.

### B. Shadow DOM Renderer Component (`frontend/src/components/CustomTrackerView.vue`)
- Mounts an isolated `ShadowRoot` on a container `<div>` (`mode: 'open'`).
- Injects a minimal reset stylesheet (`*, *::before, *::after { box-sizing: border-box; } :host { display: block; font-family: inherit; color: var(--text-primary); }`) + sanitized `custom_view.css` + hydrated/sanitized `custom_view.html`.
- Re-renders reactively whenever `tracker`, `records`, or `custom_view` changes.
- Attaches a single delegated `click` listener on the `ShadowRoot` to intercept `[data-jarvis-action]`:
  - `advance_cycle` -> emits `cycle-action('advance_cycle')`
  - `skip_cycle` -> emits `cycle-action('skip_or_shift_cycle')`
  - `open_detail` -> emits `open-detail`

### C. Integration in `DynamicTrackerCard.vue`
- If `tracker.custom_view?.enabled && tracker.custom_view?.html`:
  - Render `<CustomTrackerView />` by default.
  - Display a subtle, compact toggle button in the card corner (`Vue standard` / `Vue perso`, `min-h-[36px]`) so the user can switch back to the generic view locally at any time without losing the custom view.
- Ensure `tabular-nums` and smooth theme transitions work seamlessly inside the Shadow DOM.

---

## 6. Acceptance Criteria & Handover
1. Update `workflows/01-voice-agent-core.json` with `update_tracker_custom_view` and prompt instructions.
2. Implement sanitization, endpoints, and PHPUnit tests in `backend/` (`php artisan test` 100% passing).
3. Implement `customViewRenderer.js`, `CustomTrackerView.vue`, and `DynamicTrackerCard.vue` integration in `frontend/` (`npm run build` 0 errors, bump `sw.js` cache version).
4. Update `AGENTS.md` and `README.md`.
5. Run `./.agents/hooks/pre-commit-secrets.sh` (0 leaked secrets, valid JSON across all `workflows/*.json`).
6. Output the standard `IMPLEMENTATION REPORT` block for Loop 1 synchronization.