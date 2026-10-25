# An Arch Linux machine with systemd.
FROM archlinux:latest
RUN pacman -Syu --noconfirm --needed systemd curl ca-certificates && pacman -Scc --noconfirm
STOPSIGNAL SIGRTMIN+3
CMD ["/usr/lib/systemd/systemd"]
