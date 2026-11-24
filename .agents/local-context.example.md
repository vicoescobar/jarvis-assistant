# Jarvis Assistant — Local Infrastructure & Environment Context (Template)

> Copy this file to `.agents/local-context.md` (which is gitignored) and fill in your real infrastructure values. Never commit real IPs, credentials, or personal emails into tracked files.

## 1. Live Production Infrastructure (VPS)
- **VPS Provider & OS:** Linux VPS running Ubuntu & Nginx / CloudPanel Reverse Proxy
- **Server IPv4 (`<VPS_IP>`):** `<VPS_IP>`
- **Dedicated System User:** `jarvis`
- **App Working Directory:** `/home/jarvis/app`
- **SSH Deploy Key Path:** `~/.ssh/github_deploy`
- **Sudoers Policy:** `/etc/sudoers.d/jarvis-docker` (passwordless sudo restricted to Docker and `chown`)
- **Persistent Volumes (`1000:1000` UID/GID):**
  - `/home/jarvis/app/infra/n8n_data`
  - `/home/jarvis/app/infra/user_data`
  - `/home/jarvis/app/infra/backend_data`

## 2. Live Public Domains & Reverse Proxy Mappings
- **`<MAIN_DOMAIN>` (n8n Orchestrator):** `https://<MAIN_DOMAIN>/` -> `http://127.0.0.1:5678` (`jarvis-n8n`)
- **`<API_DOMAIN>` (Laravel 11 API Gateway):** `https://<API_DOMAIN>/` -> `http://127.0.0.1:5679` (`jarvis-backend`)
- **`<DASHBOARD_DOMAIN>` (Vue.js 3 Cockpit & PWA):** `https://<DASHBOARD_DOMAIN>/` -> `http://127.0.0.1:5680` (`jarvis-dashboard`)

## 3. Owner & Connected Accounts
- **Primary Google Workspace (`<USER_EMAIL>`):** `<USER_EMAIL>`
- **Secondary IMAP / Forwarding:** `<SECONDARY_EMAIL_OR_ALIAS>`
