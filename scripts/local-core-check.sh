#!/usr/bin/env bash
# Run the exact production core and HTTP packages without the SQLite dependency.
# This is a development aid, not a replacement for the full production suite.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/internal"
cp -R "$root/internal/captcha" "$root/internal/httpapi" "$root/internal/testkit" "$tmp/internal/"
printf 'module github.com/keroloszakaria/Jervis-Captcha\n\ngo 1.23.0\n' > "$tmp/go.mod"
cd "$tmp"
GOTOOLCHAIN=local GOPROXY=off GOSUMDB=off go test -race -count=1 ./...
