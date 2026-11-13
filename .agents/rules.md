# Jarvis Assistant — Core Agent Rules

## Identity & Purpose
Event-driven personal orchestrator & ADHD executive prosthesis, built as a production-grade portfolio monorepo.

## Tech Stack & Production Target
- **Orchestrator:** n8n (Docker on OVH VPS `57.129.174.162`, user `jarvis`, path `/home/jarvis/app`, reverse-proxy `https://jarvis.victor-giroud.fr/`)
- **AI Engine:** Google Gemini Flash API (strict JSON Schema output)
- **Future Web Cockpit (Sprint 3):** Vue.js 3 (Frontend) + Spring Boot or Laravel (Backend API)
- **CI/CD:** GitHub Actions auto-deploys `main` branch via SSH.

## Mandatory Guardrails
1. **Never commit secrets or private runtime data:** `.env`, `infra/n8n_data/`, and `infra/user_data/` must remain untracked.
2. **Use specialized skills:**
   - When working on dynamic trackers or runtime memory -> load `skills/sprint1-dynamic-trackers-engine/SKILL.md`.
   - When creating or editing n8n workflows/prompts -> load `skills/n8n-workflow-builder/SKILL.md`.
   - When designing user interactions, notifications, or Dashboard UI -> load `skills/adhd-ux-guardrails/SKILL.md`.
   - When touching `infra/` or `.github/workflows/` -> load `skills/vps-infra-deploy/SKILL.md`.