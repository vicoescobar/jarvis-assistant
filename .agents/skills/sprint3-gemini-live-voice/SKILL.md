---
name: sprint3-gemini-live-voice
description: Implement Sprint 3 (Step 3.3) — Real-time bidirectional voice mode (Gemini Multimodal Live API) inside the Vue.js 3 PWA, backed by short-lived ephemeral token issuance in Laravel 11 and tool/action execution via the Laravel API & n8n.
---
# Sprint 3 (Step 3.3) — Real-Time Bidirectional Voice Mode ("Gemini Live")

## 1. Architectural Objectives
1. **Low-RAM Server Architecture:** Keep the VPS lightweight (< 100 MB added RAM) by issuing a short-lived ephemeral auth/session token from the Laravel 11 backend (`POST /api/jarvis/live/session`) while the Vue.js 3 client streams PCM/WebAudio directly over the **Gemini Multimodal Live API** WebSocket connection.
2. **True "Back-to-Back" Conversational Flow:** Support continuous turn-taking, barge-in / interruption when the user starts speaking while Jarvis is talking, natural voice output (distinct from basic browser speechSynthesis), and visible real-time transcript + tool execution status.
3. **Actionable Tool Calling into Jarvis:** Expose function declarations in the Live session so spoken requests can trigger real Jarvis actions via `POST /api/jarvis/command` or `POST /api/trackers/...` (reading/advancing/skipping trackers, adding/deleting memory rules, checking/creating tasks, calendar events, or emails).
4. **Public Repo Hygiene:** Never expose long-lived `GEMINI_API_KEY` secrets to the frontend or commit personal data/keys to Git.

---

## 2. Backend Changes (`backend/`)

### A. Ephemeral Live Session Endpoint (`POST /api/jarvis/live/session`)
- Protected by `auth:sanctum`.
- Reads runtime context (`trackers_registry.json` and active `memory_rules.json` after purging expired volatile rules) to assemble a compact, up-to-date system instruction for the Live session (direct executive French tone, zero patronizing phrases).
- Uses `GEMINI_API_KEY` strictly server-side from `.env` (`infra/.env.exemple` updated with `GEMINI_API_KEY=` placeholder) to mint a short-lived ephemeral token / session config via Google's ephemeral token / Live API endpoint (with a clean fallback or clear error payload if the SDK/endpoint requires a specific API version).
- Returns `{ "status": "ok", "model": "...", "ws_url_or_token": "...", "expires_at": "...", "system_instruction": "..." }`.

### B. Optional Live Tool Execution Helper (`POST /api/jarvis/live/tool`)
- Protected by `auth:sanctum`.
- Accepts `{ "tool_name": "string", "arguments": { ... } }` from the Vue client when Gemini Live triggers a function call, dispatches it cleanly to the existing `N8nGatewayService` / `TrackerStorageService` / `MemoryRuleService`, and returns structured JSON tool output so the Live model can continue speaking naturally with the result.

---

## 3. Frontend Changes (`frontend/src/`)

### A. Live Voice View / Mode (`src/components/LiveVoiceSession.vue` & integration in `ChatVoiceView.vue`)
- Add a clear toggle between **Talkie-Walkie (Webhook n8n)** and **Live Temps Réel (Gemini Live WebSocket)** inside the voice experience (preserving `?view=voice&autostart=true` support).
- **Audio Capture & Playback Pipeline:**
  - Capture microphone audio via `navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, sampleRate: 16000, echoCancellation: true, noiseSuppression: true } })` and `AudioContext` / `AudioWorkletNode` (or `ScriptProcessorNode` fallback) streaming 16 kHz linear PCM chunks to the Live WebSocket.
  - Play incoming Gemini Live audio chunks smoothly via a scheduled `AudioContext` queue so playback is natural and gapless, and immediately stop/flush queued audio buffers on user interruption (`interrupted` / user voice activity).
- **UI & Observability:**
  - Show connection state (`Disconnected`, `Connecting`, `Listening`, `Jarvis Speaking`, `Tool Running`), live transcript bubbles, and round-trip latency / tool call badges in the executive French UI.
  - Ensure microphone tracks and `AudioContext` are cleanly closed on unmount or disconnect.

---

## 4. Acceptance Criteria & Handover
1. Implement `POST /api/jarvis/live/session` and `POST /api/jarvis/live/tool` in `backend/` with PHPUnit feature tests (`php artisan test` passing 100%).
2. Implement the Gemini Live WebSocket client & audio pipeline in `frontend/` and verify `npm run build` passes with zero errors.
3. Update `infra/.env.exemple`, `AGENTS.md`, `README.md`, and `docs/achitecture.mmd`.
4. Run `./.agents/hooks/pre-commit-secrets.sh` to confirm 0 hardcoded secrets and valid JSON across all `workflows/*.json`.
5. Output the standard `IMPLEMENTATION REPORT` block for Loop 1 synchronization.