# PlainShow installer: notes for developers and AI agents

The installer puts a whole PlainShow on a fresh Linux server. It is the only
repo that knows how a server is set up from nothing; the apps themselves read
everything about the server from `/etc/plainshow/platform.json` (kit
`Platform`), which the installer writes.

## Layout

- `install.sh`: first stage, in bash. Finds the Linux family (debian, fedora,
  arch, suse), makes PHP 8.4 or 8.5 available (Ondřej Surý's repositories on
  Debian and Ubuntu, Remi's on Fedora and RHEL), installs Composer, fetches
  the newest tag of this repo into `/opt/plainshow-install` and runs the PHP
  installer with the terminal as input.
- `bin/plainshow-install`: second stage. `src/Installer.php` asks the
  questions (Laravel Prompts) and runs the steps; `src/Steps.php` is the
  installation, one idempotent step per method; `src/Distro.php` holds what
  differs per family (packages, services, users, paths); `src/Templates.php`
  the Apache sites, PHP-FPM pools and systemd units; `src/Assistant.php` the
  Assistant's model (llama.cpp built here at a pinned commit for this CPU, with
  CUDA or Vulkan when there is a graphics card; the model by memory, checked by
  checksum; socket-activated units at the lowest priority); `src/State.php` the
  answers, secrets and finished steps (`/etc/plainshow/install/state.json`).
- `tests/docker/`: `run.sh <distro>` installs into a fresh systemd container
  from this checkout with `answers.json` (self-signed certificates);
  `signin.sh <distro>` signs in like a browser and opens every app;
  `resume.sh <distro>` runs the installer again in the same container.

## Rules

- Every step must be safe to run again: check, then do what is missing. A
  failed install continues from the failed step.
- Secrets never go into a command line or the log: generate them with
  `State::secret()` (they are hidden in the log) and pass them on standard
  input, in the environment or in a file.
- Apps are always installed at their newest release tag (`Apps::latest`).
  Updates in core takes over after the install; never install an app in a
  way Updates cannot update (see core's `config/updates.php`).
- What an app needs belongs in the app when it can (deploy scripts,
  migrations, `php artisan` commands); the installer prepares the server and
  calls them.

## Releases

- Semver tags `vX.Y.Z`, a `## vX.Y.Z` section at the top of `CHANGELOG.md`,
  `git tag -a vX.Y.Z -m vX.Y.Z`, `git push origin HEAD vX.Y.Z`. `install.sh`
  is served from `main` but runs the newest tag of the installer.
- GitHub Actions (`.github/workflows/install.yml`) installs from scratch on Debian,
  Ubuntu, Fedora, Arch and CachyOS and signs in to every app, on every push and
  weekly. Tag only when it is green. Locally: `tests/docker/run.sh <distro>`, then
  `tests/docker/signin.sh <distro>` (arm64 hosts: debian, ubuntu, fedora).
