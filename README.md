# PlainShow installer

Installs PlainShow on your own Linux server: a dashboard with one sign-in for
everything, and the apps you choose (Cloud, LaTeX, Blog, Split), each on its
own address under your domain. Every app is installed at its newest release;
after that the dashboard's **Updates** page keeps them current.

## Before you start

- A server with a fresh Linux. Tested on every change: Debian 13, Ubuntu 24.04,
  Fedora 42, Arch and CachyOS. Rocky, AlmaLinux and openSUSE Tumbleweed should
  work but are not tested yet. 64-bit Intel/AMD or ARM (a Raspberry Pi 4 or 5
  works), 4 GB of memory, 20 GB free.
- A domain. Point it and these subdomains at the server (DNS A or AAAA
  records): `login.`, and `cloud.`, `latex.`, `blog.`, `split.` for the apps
  you want. You can choose other addresses during the install.
- Ports 80 and 443 open to the server (forward them on your router at home).

## Install

```sh
curl -fsSL https://raw.githubusercontent.com/huggan360/plainshow-install/main/install.sh | sudo bash
```

The installer asks for:

1. your domain,
2. the apps you want,
3. their addresses (the defaults are the subdomains above),
4. certificates: Let's Encrypt, or the server's own for a test or a home network,
5. your account: username, name and password. It becomes the first administrator.

Then it installs everything: Apache, PHP, MariaDB, Node.js and Composer, the
apps, their databases, the certificates, and the background jobs. It takes 10
to 40 minutes, longer with all of TeX Live. When it is done it shows where to
sign in.

If a step fails, fix what it says and run the same command again: it
continues where it stopped. Everything is logged to
`/var/log/plainshow-install.log`.

## After the install

- Sign in at your domain with the account you chose. Create the other
  accounts under Administration, Users; each person gets a link to set their
  own password.
- **Updates** (Administration, Updates) looks for new releases every six hours
  and installs them when you choose Install, with an automatic switch back if
  a new version does not start.
- Cloud works with the Nextcloud apps for phones and computers: use your Cloud
  address as the server.

## What goes where

| Path | What |
|---|---|
| `/srv/plainshow/<app>` | the apps (releases and `current`, or a git checkout) |
| `/srv/plainshow/src` | the git checkouts releases are built from |
| `/srv/plainshow/cloud-data` | Cloud's files |
| `/etc/plainshow/platform.json` | the address of every app (no secrets) |
| `/etc/plainshow/install/state.json` | the installer's answers and secrets (root only) |
| `/etc/plainshow/apache` | the Apache sites |

The `plainshow` system user owns the code and runs Updates.

## Answers in a file

For a scripted install, put the answers in a JSON file and pass
`--answers=answers.json` (see `tests/docker/answers.json`):

```sh
curl -fsSL https://raw.githubusercontent.com/huggan360/plainshow-install/main/install.sh | sudo bash -s -- --answers=/root/answers.json
```
