---
name: dual-loop-workflow
description: Protocol for synchronizing specifications and implementation reports between the Product/Architecture AI loop (NotebookLM) and the Code Execution loop (Antigravity IDE).
---
# Dual-Loop Agentic Workflow Protocol

This project uses a strict **Two-Loop Engineering Architecture**:
1. **Loop 1 — Architect & Product Brain (External Gemini + NotebookLM):** Defines clinical ADHD constraints, system architecture, data contracts, and generates step-by-step feature skills (`SKILL.md`).
2. **Loop 2 — Code & Execution Engine (Antigravity IDE - YOU):** Implements the exact feature or workflow defined in the active `SKILL.md`, maintains code quality, enforces security hooks, and generates a structured handover report at the end of each step.

---

## Rules of Engagement for Antigravity

### Phase 1: Receiving a Feature Skill (Input)
- Before writing code or modifying infrastructure/workflows for a new feature, check `.agent` and read the specific `.agents/skills/<feature-name>/SKILL.md` provided by the user.
- Treat the JSON schemas, environment constraints, and ADHD UX guardrails inside that skill as **strict acceptance criteria**.
- Do not over-engineer or build features belonging to future sprints unless explicitly requested.

### Phase 2: Implementation & Verification (Execution)
- Keep all infrastructure changes idempotent and compatible with Ubuntu Snap Docker (`sudo docker compose ...`) and CloudPanel (`127.0.0.1` port binding).
- Ensure `.agents/hooks/pre-commit-secrets.sh` passes before committing any code or `workflows/*.json` file.

### Phase 3: End-of-Feature Handover Report (Output)
Whenever a feature/step is completed—or when the user says *"Génère le rapport de fin de step"*—you MUST generate (or update) a concise markdown block ready to be pasted back into Loop 1 (NotebookLM) following this exact template:

```markdown
# IMPLEMENTATION REPORT — [Step / Feature Name]
- **Date:** YYYY-MM-DD
- **Status:** COMPLETED | PARTIAL | BLOCKED

## 1. Files & Deliverables Modified
- List exact paths created or updated (e.g., `workflows/01-morning-briefing.json`, `infra/docker-compose.yml`).

## 2. Technical Decisions & Architecture Notes
- Any specific implementation choices, library versions, n8n node versions, or schema adjustments made during coding.

## 3. Issues Encountered & Resolutions
- Document any bugs, API quirks, permissions, or Docker/VPS issues encountered and the exact fix applied (vital for future context).

## 4. Production Verification
- Confirm what is currently live and tested on `[https://jarvis.victor-giroud.fr/](https://jarvis.victor-giroud.fr/)`.
```