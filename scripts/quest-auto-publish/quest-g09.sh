#!/usr/bin/env bash
set -euo pipefail
# Operator-run release controller. No automatic privilege elevation or schedule changes.
HERE=$(cd -- "$(dirname -- "$0")" && pwd)
exec python3 "$HERE/quest-g09-release.py" "$@"
