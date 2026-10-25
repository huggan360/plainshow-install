# An Ubuntu 24.04 machine with systemd (PHP 8.4 comes from Ondřej Surý's repository).
FROM ubuntu:24.04
RUN apt-get update && apt-get install -y --no-install-recommends systemd systemd-sysv dbus curl ca-certificates && apt-get clean
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
