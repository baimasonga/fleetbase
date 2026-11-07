#!/bin/bash
# End-to-end test of the offline field app in headless Chromium, against the
# real PoD package code (Laravel via Orchestra Testbench, SQLite).
#
#   POD_TEST_VENDOR=/path/to/vendor ./run.sh
#
# Needs: PHP 8.1+ with GD and SQLite, Node with Playwright and Chromium,
# Python 3 with Pillow (for the fake camera video).
set -euo pipefail
cd "$(dirname "$0")"
: "${POD_TEST_VENDOR:?Set POD_TEST_VENDOR to a vendor directory with orchestra/testbench}"
export POD_E2E_DIR="${POD_E2E_DIR:-$(mktemp -d)}"
export POD_E2E_URL="http://127.0.0.1:${POD_E2E_PORT:-8099}"

rm -rf "$POD_E2E_DIR/storage" && mkdir -p "$POD_E2E_DIR/storage"
php seed.php
python3 -I mjpeg.py "$POD_E2E_DIR/isatu-qr.png" "$POD_E2E_DIR/isatu.mjpeg"
python3 -I -c "from PIL import Image, ImageDraw
im = Image.new('RGB', (2000, 1500), (120, 150, 90)); d = ImageDraw.Draw(im)
d.rectangle([700, 500, 1300, 1300], fill=(230, 220, 190)); d.ellipse([850, 150, 1150, 450], fill=(90, 60, 40))
im.save('$POD_E2E_DIR/farmer.jpg', quality=92)"

PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:${POD_E2E_PORT:-8099}" server.php > "$POD_E2E_DIR/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 50); do curl -s -o /dev/null "$POD_E2E_URL/pod-field/" && break; sleep 0.2; done

node run.js
echo "Screenshots: $POD_E2E_DIR/shots"
