---
name: vps-infra-deploy
description: Manage Docker Compose, CloudPanel Nginx reverse-proxy settings, and GitHub Actions SSH deployment for the OVH VPS.
---
# VPS Infrastructure Constraints

- **Host:** `57.129.174.162` | **User:** `jarvis` | **Root Dir:** `/home/jarvis/app`
- **Docker specifics:** Docker runs via Ubuntu Snap. The `jarvis` user executes Docker via passwordless sudoers (`sudo docker compose ...`) and `n8n_data` must keep UID `1000:1000`.
- **Network:** Containers bind strictly to `127.0.0.1:<port>` because CloudPanel handles public SSL termination on `jarvis.victor-giroud.fr`.