#!/usr/bin/env bash
# Execute production acceptance with no network interface except loopback.
set -euo pipefail
python_bin="$(command -v python3)"
binary="$($python_bin -c 'import pathlib,sys; print(pathlib.Path(sys.argv[1]).resolve(strict=True))' "$1")"
sudo unshare --net -- bash -euc '
  ip link set lo up
  NATIVE_REPORT=airgap-validation.json "$1" scripts/smoke-native.py "$2" --network-isolated
' -- "$python_bin" "$binary"
