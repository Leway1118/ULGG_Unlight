#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="${ULGG_SYNC_REPO:-/root/ulgg-git-sync}"
SRC_DIR="${ULGG_SOURCE_DIR:-/var/www/html/unlight}"
KEY="${ULGG_GIT_KEY:-/root/.ssh/ulgg_github_ed25519}"
BRANCH="${ULGG_SYNC_BRANCH:-server-sync}"
EXCLUDE_FILE="$REPO_DIR/scripts/ops/server-sync.rsync-exclude"

exec 9>/run/lock/ulgg-git-sync.lock
if ! flock -n 9; then
    echo "[SKIP] another ULGG server-sync is running"
    exit 0
fi

export GIT_SSH_COMMAND="ssh -i $KEY -o IdentitiesOnly=yes -o BatchMode=yes"

cd "$REPO_DIR"

if [[ -n "$(git status --porcelain)" ]]; then
    echo "[ABORT] sync worktree is dirty before sync"
    git status --short
    exit 2
fi

git fetch origin "$BRANCH"
git checkout "$BRANCH"
git merge --ff-only "origin/$BRANCH"

rsync -a --no-owner --no-group     --exclude-from="$EXCLUDE_FILE"     "$SRC_DIR/" "$REPO_DIR/"

# Stage additions/modifications only. The sync intentionally does not delete
# repo-only source that is not deployed on the VPS.
git add -A

if git diff --cached --quiet; then
    echo "[NOOP] no source changes"
    exit 0
fi

# Path-level safety guard.
BAD_PATHS="$(git diff --cached --name-only |     grep -E '(^|/)\.env($|\.)|(^|/)(id_rsa|id_ed25519)|\.(pem|ppk|key)$|^assets/(pay|uploads)/|^cache/|^venv311/|^vendor/|^tool/raid-bot/data/' |     grep -v -E '(^|/)\.env\.example$' || true)"

if [[ -n "$BAD_PATHS" ]]; then
    echo "[ABORT] protected path would be committed:"
    echo "$BAD_PATHS"
    git reset
    exit 3
fi

# GitHub rejects individual files >100 MiB; stop before 95 MiB.
LARGE_FILES="$(find "$REPO_DIR" -type f -size +95M     -not -path "$REPO_DIR/.git/*" -print || true)"

if [[ -n "$LARGE_FILES" ]]; then
    echo "[ABORT] file >95 MiB detected:"
    echo "$LARGE_FILES"
    git reset
    exit 4
fi

# Simple explicit-secret signature scan against files being added/modified.
SECRET_HITS="$(git diff --cached --name-only --diff-filter=AM -z |     xargs -0 -r grep -IlE     'github_pat_|ghp_[A-Za-z0-9]|AKIA[0-9A-Z]{16}|BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY|discord(app)?\.com/api/webhooks/[0-9]+/[A-Za-z0-9_-]+|sk-[A-Za-z0-9]{20,}'     2>/dev/null || true)"

if [[ -n "$SECRET_HITS" ]]; then
    echo "[ABORT] possible secret detected in:"
    echo "$SECRET_HITS"
    git reset
    exit 5
fi

STAMP="$(TZ=Asia/Taipei date '+%Y-%m-%d %H:%M %Z')"
git commit -m "server-sync: $STAMP"
git push origin "$BRANCH"

echo "[OK] server-sync pushed"
git log -1 --oneline
