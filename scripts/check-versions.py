#!/usr/bin/env python3
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SEMVER = re.compile(r"^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$")


def json_version(path: Path) -> str:
    value = json.loads(path.read_text(encoding="utf-8")).get("version")
    if not isinstance(value, str) or not value:
        raise SystemExit(f"missing version in {path.relative_to(ROOT)}")
    return value


def match_version(path: Path, pattern: str, label: str) -> str:
    source = path.read_text(encoding="utf-8")
    match = re.search(pattern, source)
    if not match:
        raise SystemExit(f"could not resolve {label} in {path.relative_to(ROOT)}")
    return match.group(1)


def main() -> None:
    versions = {
        "workspace": json_version(ROOT / "package.json"),
        "frontend": json_version(ROOT / "packages/frontend/package.json"),
        "embeddedPhp": match_version(
            ROOT / "packages/php/src/Engine/EmbeddedEngine.php",
            r"public\s+const\s+VERSION\s*=\s*'([^']+)'\s*;",
            "EmbeddedEngine::VERSION",
        ),
        "nativeGo": match_version(
            ROOT / "internal/captcha/types.go",
            r'const\s+Version\s*=\s*"([^"]+)"',
            "captcha.Version",
        ),
    }
    for label, version in versions.items():
        if not SEMVER.fullmatch(version):
            raise SystemExit(f"invalid SemVer for {label}: {version!r}")
    unique = set(versions.values())
    if len(unique) != 1:
        raise SystemExit("Jervis Captcha version drift: " + ", ".join(f"{k}={v}" for k, v in versions.items()))
    version = next(iter(unique))
    print(f"Jervis Captcha version: {version}")


if __name__ == "__main__":
    main()
