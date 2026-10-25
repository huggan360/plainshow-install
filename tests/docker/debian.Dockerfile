# A Debian 13 machine with systemd, to test the installer on.
FROM debian:trixie
RUN apt-get update && apt-get install -y --no-install-recommends systemd systemd-sysv dbus curl ca-certificates && apt-get clean
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
