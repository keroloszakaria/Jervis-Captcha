#!/usr/bin/env bash
# Run on a connected build/review host, never as part of customer startup.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
# Preserve the complete report, including documentation-only development tools.
status=0
npm audit --json > npm-audit.json || status=$?
if [ "$status" -gt 1 ]; then echo 'Dependency audit service failed'; exit "$status"; fi
npm audit --omit=dev --audit-level=moderate
