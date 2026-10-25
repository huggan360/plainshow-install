# A Fedora machine with systemd, to test the installer on.
FROM fedora:42
RUN dnf -y install systemd procps-ng curl ca-certificates && dnf clean all
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
