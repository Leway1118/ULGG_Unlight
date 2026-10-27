#!/usr/bin/env bash
set -Eeuo pipefail

BASE_SHA="${1:?Usage: bash scripts/ci/run-pr-checks.sh <base-commit-sha>}"
if [[ ! "$BASE_SHA" =~ ^[0-9a-fA-F]{40}$ ]]; then
  echo "[FAIL] base must be a full commit SHA" >&2
  exit 2
fi
git cat-file -e "$BASE_SHA^{commit}"

echo "=== P0: whitespace / merge diff sanity ==="
git diff --check "$BASE_SHA" HEAD

echo "=== P0: basic safety guard ==="
python3 scripts/ci/pr_guard.py "$BASE_SHA"

echo "=== P0: changed PHP syntax ==="
mapfile -d '' PHP_FILES < <(git diff --name-only --diff-filter=ACMR -z "$BASE_SHA" HEAD -- '*.php')
if (( ${#PHP_FILES[@]} == 0 )); then
  echo "[SKIP] no changed PHP source in PR"
else
  for path in "${PHP_FILES[@]}"; do
    if [[ -f "$path" ]]; then
      php -l "$path"
    fi
  done
fi

echo "=== P0: CI guard unit tests ==="
python3 -m unittest discover -s tests/ci -p 'test_*.py' -v

echo "=== P0: raising test syntax ==="
node --check tests/raising/engine.test.mjs
node --check tests/raising/viewer-smoke.mjs

echo "=== P0: optional raising engine unit tests ==="
if [[ -f assets/js/raising/engine.js && -f scripts/import/cc_asset.json ]]; then
  node --test tests/raising/engine.test.mjs
else
  echo "::warning::Existing raising unit tests unavailable: assets/js/raising/engine.js or scripts/import/cc_asset.json missing"
  echo "[SKIP] raising runtime tests; syntax-only validation above"
fi

echo "[PASS] P0 PR checks complete; not an E2E or production deployment test"
