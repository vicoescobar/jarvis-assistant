# 🧠 Jarvis Assistant — ADHD Executive Prosthesis & AI Orchestrator

Jarvis is an event-driven personal assistant and executive function prosthesis built with **n8n**, **Google Gemini (`gemini-3.5-flash-lite`)**, **Docker Compose**, and **GitHub Actions CI/CD**.

It is designed around two core goals:
1. **Clinical ADHD Cognitive Accessibility:** Reduce cognitive friction (< 2 minutes per interaction), eliminate task-initiation paralysis via **Gollwitzer Implementation Intentions** (*"Si [Déclencheur], alors [Micro-action < 2 min]"*), enforce a strict **No-Shame / Zero-Guilt** policy, and structure creative sessions (45-min timed Ableton Live sandboxes).
2. **100% Generic & Open-Source Architecture:** Zero hardcoded personal trackers or secrets in Git. All life/work trackers (`CYCLE_STATE_MACHINE`, `COLLECTION_TABLE`, `ACTIVITY_LOG`) and contextual memory scopes are created and mutated dynamically at runtime via voice commands in an isolated `/home/node/user_data/` volume.

---

## 🏗️ System Architecture & Workflows

| Workflow File | ID | Trigger | Description |
| :--- | :--- | :--- | :--- |
| `workflows/01-voice-agent-core.json` | `01-voice-agent-core` | `POST /webhook/voice-command` | Core Voice AI Agent equipped with the **4 Universal Meta-Tools** (`create_or_update_tracker_schema`, `mutate_tracker_record`, `query_tracker_records`, `update_memory_rule`), morning briefing negotiation (`today_briefing_state`), and optional Discord confirmation dispatch. |
| `workflows/02-morning-briefing.json` | `02-morning-briefing` | Cron `08:30` (`Europe/Paris`) & `POST /webhook/morning-briefing` | Reads active trackers (`CYCLE_STATE_MACHINE`, overdue >10d `COLLECTION_TABLE` quotes, 7d `ACTIVITY_LOG`) & `morning_rules`, generates a max 4-5 line ADHD Morning Briefing, persists today's proposal in `custom_scopes.today_briefing_state`, and dispatches to Discord. |

---

## 📂 Repository Structure

```text
├── .agents/
│   ├── hooks/pre-commit-secrets.sh          # Blocks leaked API keys/webhooks & validates workflow JSONs
│   └── skills/                              # Domain & Sprint specifications (Dual-Loop Architecture)
├── .github/workflows/deploy.yml             # Automated SSH deployment & workflow import to OVH VPS
├── docs/achitecture.mmd                     # Mermaid system architecture diagram
├── infra/
│   ├── docker-compose.yml                   # Production n8n stack bound to 127.0.0.1:5678
│   └── .env.exemple                         # Environment template
└── workflows/                               # Versioned, sanitized n8n workflows
```

---

## 🚀 Deployment & Security

- **CI/CD Pipeline:** Every push to `main` runs `.github/workflows/deploy.yml`, which connects to the OVH VPS via SSH (`jarvis` user), initializes `/home/jarvis/app/infra/user_data/` (`1000:1000` UID), updates containers via `docker compose`, and imports `/workflows/*.json` into `jarvis-n8n`.
- **Pre-Commit Security Verification:**
  ```bash
  ./.agents/hooks/pre-commit-secrets.sh
  ```

