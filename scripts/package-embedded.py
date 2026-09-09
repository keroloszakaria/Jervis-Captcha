#!/usr/bin/env python3
from __future__ import annotations

import gzip
import hashlib
import io
import json
import re
import tarfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / 'packages' / 'php'
FRONTEND = ROOT / 'packages' / 'frontend'
OUT = ROOT / 'dist' / 'embedded'


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding='utf-8'))


def require_file(path: Path) -> None:
    if not path.is_file() or path.stat().st_size == 0:
        raise SystemExit(f'missing required package file: {path.relative_to(ROOT)}')


def require_exact_files(directory: Path, expected: set[str]) -> None:
    if not directory.is_dir():
        raise SystemExit(f'missing package directory: {directory.relative_to(ROOT)}')
    actual = {path.name for path in directory.iterdir() if path.is_file()}
    nested = [path for path in directory.iterdir() if path.is_dir()]
    if actual != expected or nested:
        extra = sorted(actual - expected)
        missing = sorted(expected - actual)
        raise SystemExit(
            f'unexpected runtime package contents in {directory.relative_to(ROOT)}: '
            f'missing={missing}, extra={extra}, nested={[p.name for p in nested]}'
        )


def iter_files(base: Path, roots: list[str]) -> list[Path]:
    found: list[Path] = []
    for item in roots:
        path = base / item
        if path.is_file():
            found.append(path)
        elif path.is_dir():
            found.extend(p for p in path.rglob('*') if p.is_file())
        else:
            raise SystemExit(f'missing package input: {path.relative_to(ROOT)}')
    return sorted(found, key=lambda p: p.relative_to(base).as_posix())


def engine_version() -> str:
    source = (PHP / 'src' / 'Engine' / 'EmbeddedEngine.php').read_text(encoding='utf-8')
    match = re.search(r"public\s+const\s+VERSION\s*=\s*'([^']+)'\s*;", source)
    if not match:
        raise SystemExit('could not resolve EmbeddedEngine::VERSION')
    return match.group(1)


def zip_php(version: str, source_manifest: dict) -> Path:
    target = OUT / f'jervis-captcha-php-{version}.zip'
    files = iter_files(PHP, ['composer.json', 'README.md', 'src', 'config', 'resources'])
    artifact_manifest = dict(source_manifest)
    artifact_manifest['version'] = version
    composer_bytes = (json.dumps(artifact_manifest, indent=2, ensure_ascii=False) + '\n').encode('utf-8')

    with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in files:
            relative = path.relative_to(PHP).as_posix()
            info = zipfile.ZipInfo(relative, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            data = composer_bytes if relative == 'composer.json' else path.read_bytes()
            archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return target


def tar_frontend(version: str) -> Path:
    target = OUT / f'jervis-captcha-frontend-{version}.tgz'
    files = iter_files(FRONTEND, ['package.json', 'README.md', 'dist'])
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode='w', format=tarfile.PAX_FORMAT) as archive:
        for path in files:
            relative = Path('package') / path.relative_to(FRONTEND)
            data = path.read_bytes()
            info = tarfile.TarInfo(relative.as_posix())
            info.size = len(data)
            info.mode = 0o644
            info.uid = info.gid = 0
            info.uname = info.gname = ''
            info.mtime = 0
            archive.addfile(info, io.BytesIO(data))
    with target.open('wb') as raw:
        with gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=0, compresslevel=9) as compressed:
            compressed.write(buffer.getvalue())
    return target


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def main() -> None:
    php_manifest = load_json(PHP / 'composer.json')
    frontend_manifest = load_json(FRONTEND / 'package.json')
    frontend_version = str(frontend_manifest.get('version', ''))
    runtime_version = engine_version()
    if not frontend_version or runtime_version != frontend_version:
        raise SystemExit(f'embedded package versions must match: engine={runtime_version!r}, frontend={frontend_version!r}')
    if 'version' in php_manifest:
        raise SystemExit('packages/php/composer.json must not declare a VCS version; the artifact builder injects it')

    require_exact_files(
        PHP / 'resources' / 'dist',
        {'jervis-captcha.js', 'THIRD_PARTY_NOTICES.txt'},
    )
    require_exact_files(
        FRONTEND / 'dist',
        {'jervis-captcha.js', 'index.d.ts', 'THIRD_PARTY_NOTICES.txt'},
    )
    for required in [
        PHP / 'resources' / 'dist' / 'jervis-captcha.js',
        PHP / 'resources' / 'audio' / 'digits.asset',
        FRONTEND / 'dist' / 'jervis-captcha.js',
        FRONTEND / 'dist' / 'index.d.ts',
    ]:
        require_file(required)

    OUT.mkdir(parents=True, exist_ok=True)
    for stale in OUT.glob('*'):
        if stale.is_file():
            stale.unlink()

    php_archive = zip_php(runtime_version, php_manifest)
    frontend_archive = tar_frontend(frontend_version)
    archives = [php_archive, frontend_archive]

    checksums = '\n'.join(f'{sha256(path)}  {path.name}' for path in archives) + '\n'
    (OUT / 'SHA256SUMS').write_text(checksums, encoding='utf-8', newline='\n')
    manifest = {
        'version': runtime_version,
        'mode': 'embedded-php',
        'runtimeNetworkDependency': False,
        'php': {
            'package': php_archive.name,
            'name': php_manifest['name'],
            'requires': php_manifest.get('require', {}),
        },
        'frontend': {
            'package': frontend_archive.name,
            'name': frontend_manifest['name'],
            'type': frontend_manifest.get('type'),
        },
        'sha256': {path.name: sha256(path) for path in archives},
    }
    (OUT / 'manifest.json').write_text(json.dumps(manifest, indent=2, sort_keys=True) + '\n', encoding='utf-8', newline='\n')

    for path in [*archives, OUT / 'manifest.json', OUT / 'SHA256SUMS']:
        print(path.relative_to(ROOT))


if __name__ == '__main__':
    main()
