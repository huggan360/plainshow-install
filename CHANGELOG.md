# Changelog

## v1.1.1

- The dashboard's site passes the Assistant's progress on as it happens (no buffering or compression on that one address).

## v1.1.0

- The installer can set up the Assistant: it asks (when the server has the memory for it), builds llama.cpp on the server for its processor, with CUDA for an NVIDIA card or Vulkan for another graphics card, downloads the model that fits the memory (Qwen3 1.7B, or 0.6B on 3 to 6 GB) and checks it, and starts it on demand at the lowest priority, unloading it after 30 quiet minutes. Core shows the Assistant only when it is installed.

## v1.0.0

- The first installer: an interactive terminal installer for Debian, Ubuntu, Fedora, RHEL-like, Arch and openSUSE that sets up the dashboard, Login and the chosen apps (Cloud, LaTeX, Blog, Split) at their newest releases, with Apache, PHP-FPM, MariaDB, certificates, background jobs and the first administrator.
