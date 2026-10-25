#!/usr/bin/env bash
# Runs the installer again in an existing test container, with this checkout's code: tests/docker/resume.sh debian
set -euo pipefail
NAME=plainshow-test-${1:?distro}
docker exec "$NAME" sh -c 'cp -r /opt/plainshow-install-src/src /opt/plainshow-install-src/bin /opt/plainshow-install-src/install.sh /opt/installer/'
docker exec "$NAME" php /opt/installer/bin/plainshow-install --answers=/opt/installer/tests/docker/answers.json
