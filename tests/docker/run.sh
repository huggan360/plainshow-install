#!/usr/bin/env bash
# Installs PlainShow in a fresh systemd container: tests/docker/run.sh debian
# The installer comes from this checkout (PLAINSHOW_INSTALL_DIR); the apps from GitHub.
set -euo pipefail
DISTRO=${1:?distro, for example debian}
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
NAME=plainshow-test-$DISTRO
docker build -q -t "$NAME" -f "$HERE/$DISTRO.Dockerfile" "$HERE" >/dev/null
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" --hostname "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw \
    --tmpfs /run --tmpfs /run/lock -v "$ROOT:/opt/plainshow-install-src:ro" "$NAME" >/dev/null
sleep 3
docker exec "$NAME" sh -c 'rm -rf /opt/installer && cp -r /opt/plainshow-install-src /opt/installer && rm -rf /opt/installer/vendor'
docker exec -e PLAINSHOW_INSTALL_DIR=/opt/installer "$NAME" bash /opt/installer/install.sh --answers=/opt/installer/tests/docker/answers.json
