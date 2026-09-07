"""Package already-built native binaries with local docs, inventories and hashes."""
from pathlib import Path
import hashlib
import json
import shutil
import subprocess
import zipfile

root = Path(__file__).resolve().parents[1]
out = root / "dist/releases"
out.mkdir(parents=True, exist_ok=True)
stream = subprocess.check_output(["go", "list", "-m", "-json", "all"], cwd=root, text=True)
decoder = json.JSONDecoder()
modules = []
while stream.strip():
    stream = stream.lstrip()
    value, end = decoder.raw_decode(stream)
    modules.append(value)
    stream = stream[end:]
licenses = out / "third-party-licenses"
licenses.mkdir(exist_ok=True)
for module in modules:
    if module.get("Main"):
        continue
    directory = Path(module.get("Dir", ""))
    if not directory.is_dir():
        raise RuntimeError(f"Missing module directory: {module['Path']}")
    candidates = [p for p in directory.iterdir() if p.is_file() and p.name.upper().startswith(("LICENSE", "COPYING", "COPYRIGHT"))]
    if not candidates:
        raise RuntimeError(f"Review missing license notice for {module['Path']}")
    for candidate in candidates:
        destination = licenses / module["Path"].replace("/", "_") / candidate.name
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(candidate, destination)
shutil.copy2(Path(subprocess.check_output(["go", "env", "GOROOT"], text=True).strip()) / "LICENSE", licenses / "GO_LICENSE.txt")
shutil.copy2(root / "internal/web/dist/THIRD_PARTY_NOTICES.txt", licenses / "BROWSER_NOTICES.txt")
inventory = [{key: m[key] for key in ("Path", "Version", "Sum", "GoModSum") if key in m} for m in modules]
(out / "dependency-inventory.json").write_text(json.dumps(inventory, indent=2))
for directory in sorted(out.iterdir()):
    if not directory.is_dir() or not directory.name.startswith("jervis-captcha-"):
        continue
    shutil.copy2(root / "README.md", directory / "README.md")
    shutil.copy2(root / "SECURITY.md", directory / "SECURITY.md")
    shutil.copytree(root / "docs", directory / "docs", dirs_exist_ok=True, ignore=shutil.ignore_patterns(".vitepress", "node_modules"))
    shutil.copytree(licenses, directory / "third-party-licenses", dirs_exist_ok=True)
    shutil.copy2(out / "dependency-inventory.json", directory)
    archive = directory.with_suffix(".zip")
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for path in sorted(directory.rglob("*")):
            if path.is_file():
                z.write(path, path.relative_to(out))
checksums = [f"{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.name}" for path in sorted(out.glob("*.zip"))]
(out / "SHA256SUMS").write_text("\n".join(checksums) + "\n")
