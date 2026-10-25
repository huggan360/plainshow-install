#!/usr/bin/env bash
# PlainShow installer, first stage.
#
#   curl -fsSL https://raw.githubusercontent.com/huggan360/plainshow-install/main/install.sh | sudo bash
#
# Finds out which Linux this is, makes PHP 8.4 or 8.5 available (from the
# distribution, or from the usual extra repository for it), installs PHP's
# command line, Git and Composer, fetches the newest release of the installer
# and starts it. The installer asks its questions on the terminal and does the
# rest. Run it again to continue after a failed step.
#
#   PLAINSHOW_INSTALL_REF=v1.2.0   use that release of the installer (default: the newest)
#   PLAINSHOW_INSTALL_DIR=...      use a local copy of the installer (for testing)
set -euo pipefail

REPO=https://github.com/huggan360/plainshow-install.git
STATE=/etc/plainshow/install
LOG=/var/log/plainshow-install.log

say() { printf '\033[1;38;5;202m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || fail "Run the installer as root, for example: curl -fsSL ... | sudo bash"
[ -r /etc/os-release ] || fail "This system has no /etc/os-release, so its Linux cannot be identified."
command -v systemctl >/dev/null && [ -d /run/systemd/system ] || fail "PlainShow needs a Linux that runs systemd."
case "$(uname -m)" in x86_64|aarch64) ;; *) fail "PlainShow runs on 64-bit Intel/AMD (x86_64) or ARM (aarch64), not $(uname -m)." ;; esac

# shellcheck disable=SC1091
. /etc/os-release
ID_ALL=" ${ID:-} ${ID_LIKE:-} "
case "$ID_ALL" in
    *" debian "*|*" ubuntu "*) FAMILY=debian ;;
    *" fedora "*|*" rhel "*|*" centos "*) FAMILY=fedora ;;
    *" arch "*) FAMILY=arch ;;
    *" suse "*|*" opensuse "*) FAMILY=suse ;;
    *) fail "${PRETTY_NAME:-This Linux} is not supported yet. PlainShow installs on Debian, Ubuntu, Fedora, RHEL-like (Rocky, Alma), Arch and openSUSE." ;;
esac
say "Installing PlainShow on ${PRETTY_NAME:-$ID} ($FAMILY family)"
mkdir -p "$STATE" && chmod 0700 "$STATE"
touch "$LOG" && chmod 0600 "$LOG"
exec 3>>"$LOG"
run() { printf '+ %s\n' "$*" >&3; "$@" >&3 2>&1 || { tail -n 20 "$LOG" >&2; fail "This command failed: $*  (the full log is in $LOG)"; }; }

# PHP 8.4 or 8.5: Laravel's packages need 8.4, and Nextcloud does not run on 8.6.
PHP=""
case "$FAMILY" in
debian)
    export DEBIAN_FRONTEND=noninteractive
    run apt-get update -q
    run apt-get install -y -q --no-install-recommends ca-certificates curl gnupg lsb-release
    for version in 8.5 8.4; do
        if apt-cache policy "php$version-cli" 2>/dev/null | grep 'Candidate: [0-9]' >/dev/null; then PHP=$version; break; fi
    done
    if [ -z "$PHP" ]; then
        codename=${VERSION_CODENAME:-$(lsb_release -sc)}
        if [ "${ID:-}" = ubuntu ] || [[ "${ID_LIKE:-}" == *ubuntu* ]]; then
            say "Adding Ondřej Surý's PHP repository for Ubuntu"
            codename=${UBUNTU_CODENAME:-$codename}
            run curl -fsSL -o /usr/share/keyrings/ondrej-php.asc "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C"
            echo "deb [signed-by=/usr/share/keyrings/ondrej-php.asc] https://ppa.launchpadcontent.net/ondrej/php/ubuntu $codename main" > /etc/apt/sources.list.d/ondrej-php.list
        else
            say "Adding Ondřej Surý's PHP repository for Debian"
            run curl -fsSL -o /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
            run dpkg -i /tmp/debsuryorg-archive-keyring.deb
            echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $codename main" > /etc/apt/sources.list.d/php.list
        fi
        run apt-get update -q
        apt-cache policy php8.4-cli 2>/dev/null | grep 'Candidate: [0-9]' >/dev/null && PHP=8.4
    fi
    [ -n "$PHP" ] || fail "PHP 8.4 is not available for this release of ${PRETTY_NAME:-$ID}."
    run apt-get install -y -q --no-install-recommends "php$PHP-cli" "php$PHP-mbstring" "php$PHP-xml" "php$PHP-curl" "php$PHP-zip" "php$PHP-intl" git unzip xz-utils
    PHP_BIN=/usr/bin/php$PHP
    ;;
fedora)
    for version in 8.5 8.4; do
        if dnf -q list --available "php-cli-$version*" >/dev/null 2>&1 || rpm -q --qf '%{VERSION}' php-cli 2>/dev/null | grep "^$version" >/dev/null; then PHP=$version; break; fi
    done
    if [ -z "$PHP" ]; then
        say "Adding Remi's PHP repository"
        major=${VERSION_ID%%.*}
        if [ "${ID:-}" = fedora ]; then
            run dnf -y install "https://rpms.remirepo.net/fedora/remi-release-$major.rpm"
        else
            run dnf -y install "https://dl.fedoraproject.org/pub/epel/epel-release-latest-$major.noarch.rpm"
            run dnf -y install "https://rpms.remirepo.net/enterprise/remi-release-$major.rpm"
            command -v crb >/dev/null && run crb enable || true
        fi
        if dnf -q module list php >/dev/null 2>&1; then
            run dnf -y module reset php
            run dnf -y module enable php:remi-8.4
        else
            run dnf -y config-manager setopt remi-modular.enabled=1 2>/dev/null || true
            run dnf -y install dnf-plugins-core
            run dnf -y config-manager --set-enabled remi-modular 2>/dev/null || true
            run dnf -y module enable php:remi-8.4 2>/dev/null || true
        fi
        PHP=8.4
    fi
    run dnf -y install php-cli php-mbstring php-xml php-pecl-zip php-intl php-process git unzip xz curl tar
    PHP_BIN=/usr/bin/php
    ;;
arch)
    run pacman -Sy --noconfirm --needed archlinux-keyring
    run pacman -Su --noconfirm
    run pacman -S --noconfirm --needed php git unzip xz curl ca-certificates
    PHP=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
    # Arch ships the extensions turned off; the installer needs these now.
    mkdir -p /etc/php/conf.d
    printf 'extension=iconv\nextension=intl\nextension=zip\n' > /etc/php/conf.d/00-plainshow-installer.ini
    pacman -Q php-intl >/dev/null 2>&1 || run pacman -S --noconfirm --needed php-intl 2>/dev/null || true
    PHP_BIN=/usr/bin/php
    ;;
suse)
    run zypper -n refresh
    run zypper -n install php8 php8-cli php8-mbstring php8-xmlreader php8-xmlwriter php8-dom php8-curl php8-zip php8-intl php8-openssl php8-phar php8-iconv php8-posix php8-tokenizer php8-ctype php8-fileinfo git unzip xz curl tar
    PHP=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
    PHP_BIN=/usr/bin/php
    ;;
esac

version=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
case "$version" in 8.4|8.5) ;; *) fail "This system has PHP $version; PlainShow needs PHP 8.4 or 8.5." ;; esac
say "PHP $version is ready"

# Composer, from getcomposer.org, checked against its published checksum.
if ! command -v composer >/dev/null; then
    say "Installing Composer"
    run curl -fsSL -o /tmp/composer.phar https://getcomposer.org/download/latest-stable/composer.phar
    expected=$(curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar.sha256 | cut -d' ' -f1)
    [ "$(sha256sum /tmp/composer.phar | cut -d' ' -f1)" = "$expected" ] || fail "Composer's download did not match its checksum."
    install -m 0755 /tmp/composer.phar /usr/local/bin/composer
fi

# The installer itself: the newest release, unless told otherwise.
if [ -n "${PLAINSHOW_INSTALL_DIR:-}" ]; then
    DIR=$PLAINSHOW_INSTALL_DIR
else
    REF=${PLAINSHOW_INSTALL_REF:-$(git ls-remote --tags --refs "$REPO" 'v*' | sed 's#.*refs/tags/##' | sort -V | tail -n 1)}
    [ -n "$REF" ] || fail "Could not find a release of the installer on GitHub."
    DIR=/opt/plainshow-install
    say "Fetching the installer $REF"
    rm -rf "$DIR.new"
    run git -c advice.detachedHead=false clone -q --depth 1 --branch "$REF" "$REPO" "$DIR.new"
    rm -rf "$DIR" && mv "$DIR.new" "$DIR"
fi
(cd "$DIR" && COMPOSER_ALLOW_SUPERUSER=1 run composer install --no-dev --no-interaction --no-progress --quiet)

cat > "$STATE/bootstrap.env" <<EOF
FAMILY=$FAMILY
PHP_VERSION=$version
PHP_BIN=$PHP_BIN
EOF

# Questions need a terminal, also when this script came through a pipe.
if [ -t 0 ]; then
    exec "$PHP_BIN" "$DIR/bin/plainshow-install" "$@"
elif (exec </dev/tty) 2>/dev/null; then
    exec "$PHP_BIN" "$DIR/bin/plainshow-install" "$@" < /dev/tty
else
    exec "$PHP_BIN" "$DIR/bin/plainshow-install" "$@"
fi
