#!/bin/sh
# Writes the console's runtime config from the service's variables, so the
# console knows where the API and the socket server are.
#
#   API_HOST              https://api.example.org (required)
#   SOCKETCLUSTER_HOST    socket.example.org (no scheme)
#   SOCKETCLUSTER_PORT    443 (default)
#   SOCKETCLUSTER_SECURE  true (default)
#   OSRM_HOST             routing server (default: the public OSRM demo server)
set -eu

json_string() {
  case "$1" in
    *\"* | *\\*) echo "40-fleetbase-config: $2 must not contain quotes or backslashes" >&2; exit 1 ;;
  esac
  printf '"%s"' "$1"
}

if [ -z "${API_HOST:-}" ]; then
  echo "40-fleetbase-config: API_HOST is not set; the console will not reach the API." >&2
fi

{
  printf '{\n'
  printf '  "API_HOST": %s,\n' "$(json_string "${API_HOST:-}" API_HOST)"
  printf '  "SOCKETCLUSTER_HOST": %s,\n' "$(json_string "${SOCKETCLUSTER_HOST:-}" SOCKETCLUSTER_HOST)"
  printf '  "SOCKETCLUSTER_PORT": %s,\n' "$(json_string "${SOCKETCLUSTER_PORT:-443}" SOCKETCLUSTER_PORT)"
  printf '  "SOCKETCLUSTER_SECURE": %s,\n' "$(json_string "${SOCKETCLUSTER_SECURE:-true}" SOCKETCLUSTER_SECURE)"
  printf '  "OSRM_HOST": %s\n' "$(json_string "${OSRM_HOST:-https://router.project-osrm.org}" OSRM_HOST)"
  printf '}\n'
} > /usr/share/nginx/html/fleetbase.config.json

echo "40-fleetbase-config: console will use API ${API_HOST:-<unset>}"
