# Jarvis Assistant — Core Agent Rules

> **For local deployment and real infrastructure specifics (VPS IP, live domains, SSH paths), read `.agents/local-context.md` if present (gitignored). Never copy values from that file into tracked files.**

See [`AGENTS.md`](../AGENTS.md) at the repository root for the single source of truth on system architecture, data contracts, and repository structure.

## Mandatory Guardrails
1. **Never commit secrets, private infrastructure IPs/emails, or runtime data:** `.env`, `.agents/local-context.md`, `*.local.md`, `infra/n8n_data/`, `infra/user_data/`, and `infra/backend_data/` must remain untracked. Always run `./.agents/hooks/pre-commit-secrets.sh` before committing.
2. **Use specialized permanent skills in `.agents/skills/`:**
   - Architecture & handover protocol -> `.agents/skills/dual-loop-workflow/SKILL.md`
   - Cognitive accessibility, executive tone & UI/prompt ergonomics -> `.agents/skills/executive-ux-guardrails/SKILL.md`
   - Creating or editing n8n workflows & Gemini schemas -> `.agents/skills/n8n-workflow-builder/SKILL.md`
   - Touching `infra/`, Docker Compose, or `.github/workflows/` -> `.agents/skills/vps-infra-deploy/SKILL.md`
   - Gesture/spring UI motion & visual polish -> `.agents/skills/apple-design/SKILL.md` & `.agents/skills/unslop/SKILL.md`
