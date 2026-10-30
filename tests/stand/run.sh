#!/usr/bin/env bash
# Local stand: fake Enhance API over HTTPS + WHMCS stand-ins on SQLite.
# Needs php 8.1+ with pdo_sqlite and curl, composer, python3, openssl.
#   tests/stand/run.sh        # PASS: the last line is ALL PASS
set -euo pipefail
cd "$(dirname "$0")"
[ -d vendor ] || composer install --quiet --no-interaction
mkdir -p .certs
if [ ! -f .certs/cert.pem ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost" -keyout .certs/key.pem -out .certs/cert.pem 2>/dev/null
fi
python3 fake_enhance.py 8443 .certs/cert.pem .certs/key.pem > .certs/fake.log 2>&1 &
FAKE=$!
trap 'kill $FAKE 2>/dev/null || true' EXIT
for _ in $(seq 1 50); do grep -q ready .certs/fake.log 2>/dev/null && break; sleep 0.1; done
php -d curl.cainfo="$PWD/.certs/cert.pem" -d openssl.cafile="$PWD/.certs/cert.pem" -d error_reporting="E_ALL & ~E_DEPRECATED" -d display_errors=stderr run.php
