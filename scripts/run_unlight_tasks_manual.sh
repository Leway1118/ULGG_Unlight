#!/bin/bash
set -e

# === 新專案根目錄 ===
BASE_DIR="/var/www/html/unlight"
WATCHER_DIR="$BASE_DIR/watcher"
VENV_PY="$BASE_DIR/venv311/bin/python"

echo "=== RUN UNLIGHT IMPORTER ==="
echo "BASE_DIR=$BASE_DIR"
echo "WATCHER_DIR=$WATCHER_DIR"
echo "PYTHON=$VENV_PY"
echo "PWD=$(pwd)"

cd "$WATCHER_DIR"

"$VENV_PY" "$WATCHER_DIR/import_and_compare.py"
