#!/usr/bin/env bash
# Deterministic security & syntax check before committing n8n workflows and infra files.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

echo "🔍 [Jarvis Security Hook] Scanning repository for leaked secrets and validating JSON..."

ERRORS=0

# 1. Verify that private runtime directories are not tracked by Git
if git ls-files --error-unmatch infra/n8n_data infra/user_data .env infra/.env >/dev/null 2>&1; then
  echo "❌ ERROR: Private runtime data or .env file is tracked by Git!"
  ERRORS=$((ERRORS + 1))
fi

# 2. Scan workflows/ and infra/ for hardcoded secrets (Google API keys, Discord webhooks/tokens, hardcoded emails)
SECRET_PATTERNS=(
  'AIza[0-9A-Za-z_-]{35}'
  'https://discord(app)?\.com/api/webhooks/[0-9]+/[0-9A-Za-z_-]+'
  'victorgiroud4@gmail\.com'
  'ya29\.[0-9A-Za-z_-]+'
)

SCAN_TARGETS=()
[ -d "workflows" ] && SCAN_TARGETS+=("workflows")
[ -d "infra" ] && SCAN_TARGETS+=("infra")

if [ ${#SCAN_TARGETS[@]} -gt 0 ]; then
  for pattern in "${SECRET_PATTERNS[@]}"; do
    if grep -RInE "$pattern" "${SCAN_TARGETS[@]}" 2>/dev/null; then
      echo "❌ ERROR: Potential hardcoded secret or personal identifier matching '$pattern' found in ${SCAN_TARGETS[*]}!"
      ERRORS=$((ERRORS + 1))
    fi
  done
fi

# 3. Validate JSON syntax for all workflows/*.json files
if [ -d "workflows" ]; then
  shopt -s nullglob
  for wf in workflows/*.json; do
    if command -v python3 >/dev/null 2>&1; then
      if ! python3 -m json.tool "$wf" >/dev/null 2>&1; then
        echo "❌ ERROR: Invalid JSON syntax in $wf"
        ERRORS=$((ERRORS + 1))
      else
        echo "✅ Valid JSON: $wf"
      fi
    elif command -v node >/dev/null 2>&1; then
      if ! node -e "JSON.parse(require('fs').readFileSync('$wf', 'utf8'))" >/dev/null 2>&1; then
        echo "❌ ERROR: Invalid JSON syntax in $wf"
        ERRORS=$((ERRORS + 1))
      else
        echo "✅ Valid JSON: $wf"
      fi
    fi
  done
fi

if [ "$ERRORS" -gt 0 ]; then
  echo "🚫 [Jarvis Security Hook] FAILED with $ERRORS error(s). Please sanitize before committing."
  exit 1
fi

echo "✅ [Jarvis Security Hook] All checks passed (0 leaked secrets, valid workflow JSONs)."
exit 0

