# A CachyOS machine with systemd (Arch-based; x86 only).
FROM cachyos/cachyos:latest
RUN pacman -Syu --noconfirm --needed systemd curl ca-certificates && pacman -Scc --noconfirm
STOPSIGNAL SIGRTMIN+3
CMD ["/usr/lib/systemd/systemd"]
