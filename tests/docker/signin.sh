#!/usr/bin/env bash
# Signs in to a test install as the administrator, like a browser: tests/docker/signin.sh debian
# Follows the dashboard's sign-in through Login, then opens every app with the same cookies.
set -euo pipefail
NAME=plainshow-test-${1:?distro}
docker exec -i "$NAME" bash -s <<'SCRIPT'
set -euo pipefail
domain=$(php -r 'echo json_decode(file_get_contents("/etc/plainshow/platform.json"), true)["domain"];')
jar=$(mktemp); page=$(mktemp)
get() { curl -sS -L -b "$jar" -c "$jar" -o "$page" -w '%{http_code} %{url_effective}' "$@"; }
echo "dashboard: $(get "https://$domain/")"
token=$(grep -o 'name="_token" value="[^"]*"' "$page" | head -1 | sed 's/.*value="//;s/"$//')
[ -n "$token" ] || { echo "No sign-in form found"; exit 1; }
echo "sign in: $(get --data-urlencode "_token=$token" --data-urlencode login=friend --data-urlencode password=a-test-password-1 "https://login.$domain/login")"
grep -q 'data-page=' "$page" && echo "dashboard page is the Inertia app" || { echo "Not on the dashboard after signing in"; grep -o '<title>[^<]*' "$page"; exit 1; }
for app in blog split latex; do echo "$app: $(get "https://$app.$domain/")"; done
echo "cloud: $(get -H 'Accept: text/html' "https://cloud.$domain/index.php/login")"
grep -o '<title>[^<]*' "$page" | head -1
SCRIPT
