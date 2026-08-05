#!/bin/bash

SCRIPT_DIR="/var/www/watcher"
cd "$SCRIPT_DIR"

/usr/bin/python3.11 "$SCRIPT_DIR/import_and_compare.py"
