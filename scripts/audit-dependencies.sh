#!/usr/bin/env bash
# Connected build/review host only. Never run as part of customer startup.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
# Include build/documentation dependencies; do not hide development advisories.
npm audit --audit-level=moderate --json > npm-audit.json
npm audit --omit=dev --audit-level=moderate
