---
name: sprint1-dynamic-trackers-engine
description: Setup the private user_data volume and implement the Schema-less Dynamic Trackers & Memory Engine (Meta-Tools) for Sprint 1.
---
# Sprint 1 (Step 1.1) — Dynamic Trackers & Context Memory Engine

## 1. Architectural Objective (Privacy & Replicability)
Jarvis must be **100% generic and open-source ready** in the public Git repository (`vicoescobar/jarvis-assistant`), while allowing the user to create custom life/business trackers and contextual memory rules **entirely via voice commands at runtime** without ever editing code or committing personal use-cases to Git.

---

## 2. Infrastructure Changes Required

### A. Update `.gitignore`
Add the private runtime data directory so personal trackers and memory rules are never committed:

    # Private runtime trackers & contextual memory
    user_data/
    infra/user_data/

### B. Update `infra/docker-compose.yml`
Mount `/home/jarvis/app/infra/user_data` into the `jarvis-n8n` container at `/home/node/user_data` alongside `n8n_data`:

    volumes:
      - ./n8n_data:/home/node/.n8n
      - ./user_data:/home/node/user_data
      - ../workflows:/home/node/workflows

### C. Update `.github/workflows/deploy.yml`
Ensure `/home/jarvis/app/infra/user_data` is created on the VPS with UID `1000:1000` (`node` user) and initialize minimal default JSON files if they do not exist yet (only `morning_rules` is pre-initialized as a core feature; all other scopes are created dynamically by the agent):

    mkdir -p /home/jarvis/app/infra/n8n_data /home/jarvis/app/infra/user_data
    [ -f /home/jarvis/app/infra/user_data/trackers_registry.json ] || echo '{"trackers":{}}' > /home/jarvis/app/infra/user_data/trackers_registry.json
    [ -f /home/jarvis/app/infra/user_data/memory_rules.json ] || echo '{"morning_rules":[],"custom_scopes":{}}' > /home/jarvis/app/infra/user_data/memory_rules.json
    sudo chown -R 1000:1000 /home/jarvis/app/infra/n8n_data /home/jarvis/app/infra/user_data

---

## 3. Data Contracts in `/home/node/user_data/`

### 1. `trackers_registry.json`
Stores the schema and behavior of all user-created trackers:

    {
      "trackers": {
        "<tracker_id>": {
          "display_name": "string",
          "type": "CYCLE_STATE_MACHINE | COLLECTION_TABLE | ACTIVITY_LOG",
          "include_in_morning_briefing": true,
          "cycle_config": {
            "steps": ["Step 1", "Step 2", "Rest"],
            "current_index": 0,
            "last_updated": "YYYY-MM-DD"
          },
          "fields_schema": [
            { "name": "field_name", "type": "string | date | status | number", "required": true }
          ]
        }
      }
    }

### 2. `data_<tracker_id>.json`
Stores the records for a given tracker (array of JSON objects with automatic `id`, `created_at`, and `updated_at` timestamps).

### 3. `memory_rules.json` (Base `morning_rules` + Agent-Created Dynamic Scopes)
Stores core morning briefing rules and arbitrary dynamic/volatile context scopes created on the fly by the agent:

    {
      "morning_rules": [
        {
          "id": "rule_uuid",
          "instruction": "string",
          "created_at": "ISO-8601"
        }
      ],
      "custom_scopes": {
        "<dynamic_scope_name>": [
          {
            "id": "rule_uuid",
            "instruction": "string",
            "volatile": true,
            "expires_at": "YYYY-MM-DD or null",
            "created_at": "ISO-8601"
          }
        ]
      }
    }

---

## 4. The 4 Generic Meta-Tools (Gemini Function Calling in n8n)
Create the base workflow `workflows/01-voice-agent-core.json` exposing a Webhook (`POST /webhook/voice-command`) connected to an n8n **AI Agent (Gemini Flash)** equipped with these 4 generic tools:
1. `create_or_update_tracker_schema`: Creates or updates a tracker definition in `trackers_registry.json` and initializes `data_<tracker_id>.json`.
2. `mutate_tracker_record`: Inserts/updates a record in `data_<tracker_id>.json`, OR advances/skips/shifts the `current_index` of a `CYCLE_STATE_MACHINE` tracker without guilt.
3. `query_tracker_records`: Reads `data_<tracker_id>.json` and filters records by status, date elapsed (e.g., pending items older than X days), or recency (last 7 days of activities).
4. `update_memory_rule`: Adds, updates, or deletes a rule inside `morning_rules` OR dynamically creates/updates any `<dynamic_scope_name>` inside `custom_scopes` (supporting `volatile` / `expires_at` for temporary context) in `memory_rules.json`.

---

## 5. Acceptance Criteria & Handover
1. Update `.agent`, `.gitignore`, `infra/docker-compose.yml`, and `.github/workflows/deploy.yml`.
2. Generate `workflows/01-voice-agent-core.json` (sanitized, no hardcoded API keys).
3. Run `.agents/hooks/pre-commit-secrets.sh` and output the `IMPLEMENTATION REPORT` once ready.