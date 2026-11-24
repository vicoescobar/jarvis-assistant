---
name: vps-infra-deploy
description: Manage Docker Compose, CloudPanel Nginx reverse-proxy settings, and GitHub Actions SSH deployment for the Linux VPS.
---
# VPS Infrastructure Constraints

> For local deployment and real infrastructure specifics (VPS IP, live domains, SSH paths), read `.agents/local-context.md` if present (gitignored). Never copy values from that file into tracked files.

- **Host:** `<VPS_IP>` | **User:** `jarvis` | **Root Dir:** `/home/jarvis/app`
- **Docker specifics:** Docker runs via Ubuntu Snap. The `jarvis` user executes Docker via passwordless sudoers (`sudo docker compose ...`) and persistent volumes (`n8n_data`, `user_data`, `backend_data`) must maintain UID/GID `1000:1000`.
- **Network:** Containers bind strictly to `127.0.0.1:<port>` (`5678` for `jarvis-n8n`, `5679` for `jarvis-backend`, `5680` for `jarvis-dashboard`) while CloudPanel/Nginx handles public SSL termination on `<MAIN_DOMAIN>`, `<API_DOMAIN>`, and `<DASHBOARD_DOMAIN>`.
