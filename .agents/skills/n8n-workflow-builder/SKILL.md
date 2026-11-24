---
name: n8n-workflow-builder
description: Generate, inspect, and sanitize n8n workflow JSON files in /workflows/ and Gemini API structured output schemas.
---
# n8n Workflow & Gemini Integration Guidelines

- **Versioning:** All n8n workflows live in `/workflows/XX-name.json`.
- **Sanitization:** Never hardcode API keys, Discord tokens, or personal emails inside workflow JSON nodes; always reference n8n Credentials or `$env` variables.
- **Expression Safety:** Inside any n8n parameter prefixed with `=` (an n8n expression), never embed literal `{{ ... }}` documentation examples directly; pass them via a Code node output property (e.g., `{{ $json.custom_view_guidelines }}`).
- **Gemini Calls:** Enforce `responseMimeType: "application/json"` with an explicit `responseSchema` whenever extracting structured actions, tasks, or `tracker_mutations` from emails, calendars, or chat logs.
- **Voice Bridge (`#jarvis`):** Tasks polled from Google Tasks starting with `#jarvis` must be parsed into `{ action, target_time, channel, payload }` before execution.
