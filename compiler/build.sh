#!/bin/bash
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "=== Installing dev production deps ==="
cd "$ROOT/dev" && composer install --no-dev --classmap-authoritative

echo "=== Removing phpstan from vendor (loaded externally) ==="
rm -rf "$ROOT/dev/vendor/phpstan"

echo "=== Installing compiler deps ==="
cd "$ROOT/compiler" && composer install

echo "=== Compiling PHAR ==="
cd "$ROOT/compiler"
php vendor/bin/box compile --config box.json --no-parallel

echo "=== Done: $ROOT/xray.phar ==="
ls -lh "$ROOT/xray.phar"
