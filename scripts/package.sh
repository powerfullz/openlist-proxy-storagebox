#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mkdir -p "$ROOT/dist"

cd "$ROOT"

files=(.htaccess dl.php bootstrap.php config.sample.php src scripts/sign.php tests)

if command -v zip >/dev/null 2>&1; then
    zip -qr "$ROOT/dist/openlist-proxy.zip" "${files[@]}" \
        -x 'config.php' \
        -x '*/.DS_Store'
    echo "built dist/openlist-proxy.zip"
else
    tar -czf "$ROOT/dist/openlist-proxy.tar.gz" \
        --exclude='config.php' \
        --exclude='*/.DS_Store' \
        "${files[@]}"
    echo "built dist/openlist-proxy.tar.gz"
fi
