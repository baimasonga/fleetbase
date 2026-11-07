#!/bin/sh
# Prints fresh secrets to paste into Railway's shared variables. Run it once,
# keep the output in your password manager, and don't commit it anywhere.
#
#   sh railway/generate-secrets.sh
set -eu
rand() { openssl rand -base64 "$1" | tr -d '\n'; }
echo "APP_KEY=base64:$(rand 32)"
echo "POD_CARD_SECRET=$(rand 48)"
echo "POD_ID_HASH_KEY=$(rand 48)"
echo "SOCKETCLUSTER_AUTH_KEY=$(rand 48)"
echo
echo "# POD_CARD_SECRET signs the printed farmer cards and POD_ID_HASH_KEY protects ID numbers:"
echo "# changing either later invalidates every printed card / stops matching IDs. Back them up."
