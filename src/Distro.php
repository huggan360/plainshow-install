<?php

declare(strict_types=1);

namespace PlainShow\Install;

/**
 * What differs between Linux families: package names, service names, the web
 * server's user and where Apache and PHP-FPM keep their configuration.
 * install.sh has already picked the family and made PHP 8.4 or 8.5 available.
 */
final class Distro
{
    public function __construct(
        public readonly string $family,
        public readonly string $php,
    ) {
    }

    public static function fromBootstrap(string $file = '/etc/plainshow/install/bootstrap.env'): self
    {
        $values = is_file($file) ? (parse_ini_file($file) ?: []) : [];
        $family = (string) ($values['FAMILY'] ?? '');
        if (! in_array($family, ['debian', 'fedora', 'arch', 'suse'], true)) {
            throw new \RuntimeException('Start the installer with install.sh, which sets up PHP for this Linux first.');
        }

        return new self($family, (string) ($values['PHP_VERSION'] ?? PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION));
    }

    public function name(): string
    {
        $release = is_file('/etc/os-release') ? (parse_ini_file('/etc/os-release') ?: []) : [];

        return (string) ($release['PRETTY_NAME'] ?? $this->family);
    }

    /* ---------- Packages ---------- */

    /** @param list<string> $packages */
    public function installCommand(array $packages): array
    {
        return match ($this->family) {
            'debian' => ['apt-get', 'install', '-y', '-q', '--no-install-recommends', ...$packages],
            'fedora' => ['dnf', '-y', 'install', ...$packages],
            'arch' => ['pacman', '-S', '--noconfirm', '--needed', ...$packages],
            'suse' => ['zypper', '-n', 'install', '--no-recommends', ...$packages],
        };
    }

    /** Whether the package manager knows a package (for the ones some releases lack). */
    public function hasPackageCommand(string $package): array
    {
        return match ($this->family) {
            'debian' => ['sh', '-c', 'apt-cache policy '.escapeshellarg($package).' | grep -q "Candidate: [0-9]"'],
            'fedora' => ['dnf', '-q', 'list', '--available', '--installed', $package],
            'arch' => ['pacman', '-Si', $package],
            'suse' => ['zypper', '-n', '-q', 'info', $package],
        };
    }

    /** PHP with every extension the apps use. */
    public function phpPackages(): array
    {
        $v = $this->php;

        return match ($this->family) {
            'debian' => array_map(fn ($ext) => "php$v-$ext", ['cli', 'fpm', 'mysql', 'mbstring', 'xml', 'curl', 'zip', 'gd', 'intl', 'bcmath', 'gmp', 'apcu', 'redis', 'imagick', 'bz2', 'readline', 'opcache', 'sqlite3']),
            'fedora' => ['php-cli', 'php-fpm', 'php-mysqlnd', 'php-mbstring', 'php-xml', 'php-pecl-zip', 'php-gd', 'php-intl', 'php-bcmath', 'php-gmp', 'php-pecl-apcu', 'php-pecl-redis6', 'php-pecl-imagick', 'php-opcache', 'php-process', 'php-sodium', 'php-pdo'],
            'arch' => ['php', 'php-fpm', 'php-gd', 'php-intl', 'php-sodium', 'php-apcu', 'php-redis', 'php-imagick', 'php-sqlite'],
            'suse' => ['php8', 'php8-cli', 'php8-fpm', 'php8-mysql', 'php8-mbstring', 'php8-xmlreader', 'php8-xmlwriter', 'php8-dom', 'php8-simplexml', 'php8-curl', 'php8-zip', 'php8-gd', 'php8-intl', 'php8-bcmath', 'php8-gmp', 'php8-APCu', 'php8-redis', 'php8-imagick', 'php8-bz2', 'php8-opcache', 'php8-sodium', 'php8-posix', 'php8-pcntl', 'php8-fileinfo', 'php8-iconv', 'php8-ctype', 'php8-tokenizer', 'php8-openssl', 'php8-pdo', 'php8-exif', 'php8-sqlite', 'php8-zlib'],
        };
    }

    /** Everything else PlainShow needs, besides PHP. */
    public function basePackages(): array
    {
        return match ($this->family) {
            'debian' => ['apache2', 'mariadb-server', 'mariadb-client', 'git', 'unzip', 'xz-utils', 'curl', 'acl', 'sudo', 'openssl', 'ca-certificates', 'rsync', 'python3', 'imagemagick'],
            'fedora' => ['httpd', 'mod_ssl', 'mariadb-server', 'mariadb', 'git', 'unzip', 'xz', 'curl', 'acl', 'sudo', 'openssl', 'ca-certificates', 'rsync', 'python3', 'tar', 'policycoreutils-python-utils', 'ImageMagick'],
            'arch' => ['apache', 'mariadb', 'git', 'unzip', 'xz', 'curl', 'acl', 'sudo', 'openssl', 'ca-certificates', 'rsync', 'python', 'tar', 'imagemagick'],
            'suse' => ['apache2', 'mariadb', 'mariadb-client', 'git', 'unzip', 'xz', 'curl', 'acl', 'sudo', 'openssl', 'ca-certificates', 'rsync', 'python3', 'tar', 'ImageMagick'],
        };
    }

    public function certbotPackages(): array
    {
        return match ($this->family) {
            'suse' => ['python3-certbot'],
            default => ['certbot'],
        };
    }

    /** Redis (or Valkey, its successor where a distribution switched) for Cloud's file locks. */
    public function redisCandidates(): array
    {
        return match ($this->family) {
            'debian' => [['redis-server', 'redis-server']],
            'fedora' => [['valkey', 'valkey'], ['redis', 'redis']],
            'arch' => [['valkey', 'valkey'], ['redis', 'redis']],
            'suse' => [['valkey', 'valkey'], ['redis', 'redis@default']],
        };
    }

    /** TeX Live for LaTeX: the basics, a solid everyday set, or everything. */
    public function texPackages(string $size): array
    {
        if ($size === 'basic') {
            return match ($this->family) {
                'debian' => ['texlive-latex-base', 'texlive-latex-recommended', 'texlive-fonts-recommended', 'latexmk', 'lmodern'],
                'fedora' => ['texlive-scheme-basic', 'texlive-collection-latexrecommended', 'latexmk'],
                'arch' => ['texlive-basic', 'texlive-latex', 'texlive-latexrecommended', 'texlive-fontsrecommended', 'texlive-binextra'],
                'suse' => ['texlive-scheme-basic', 'texlive-collection-latexrecommended', 'texlive-latexmk'],
            };
        }
        $full = $size === 'full';

        return match ($this->family) {
            'debian' => $full ? ['texlive-full'] : ['texlive-latex-base', 'texlive-latex-recommended', 'texlive-latex-extra', 'texlive-fonts-recommended', 'texlive-fonts-extra', 'texlive-bibtex-extra', 'texlive-science', 'texlive-xetex', 'texlive-luatex', 'texlive-lang-european', 'biber', 'latexmk', 'lmodern'],
            'fedora' => $full ? ['texlive-scheme-full'] : ['texlive-scheme-medium', 'latexmk', 'biber'],
            'arch' => $full ? ['texlive', 'texlive-lang', 'biber'] : ['texlive-basic', 'texlive-latex', 'texlive-latexrecommended', 'texlive-latexextra', 'texlive-fontsrecommended', 'texlive-fontsextra', 'texlive-bibtexextra', 'texlive-science', 'texlive-xetex', 'texlive-luatex', 'biber'],
            'suse' => $full ? ['texlive-scheme-full'] : ['texlive-scheme-medium', 'texlive-latexmk', 'biber'],
        };
    }

    /* ---------- Services and paths ---------- */

    public function webService(): string
    {
        return match ($this->family) {
            'debian', 'suse' => 'apache2',
            'fedora', 'arch' => 'httpd',
        };
    }

    public function webUser(): string
    {
        return match ($this->family) {
            'debian' => 'www-data',
            'fedora' => 'apache',
            'arch' => 'http',
            'suse' => 'wwwrun',
        };
    }

    public function fpmService(): string
    {
        return $this->family === 'debian' ? "php{$this->php}-fpm" : 'php-fpm';
    }

    public function fpmPoolDir(): string
    {
        return match ($this->family) {
            'debian' => "/etc/php/{$this->php}/fpm/pool.d",
            'fedora' => '/etc/php-fpm.d',
            'arch' => '/etc/php/php-fpm.d',
            'suse' => '/etc/php8/fpm/php-fpm.d',
        };
    }

    /** Where the PHP-FPM sockets go (SELinux on Fedora and RHEL expects them in /run/php-fpm). */
    public function socketDir(): string
    {
        return $this->family === 'fedora' ? '/run/php-fpm' : '/run';
    }

    /** Where Apache reads our one include line from. */
    public function apacheIncludeFile(): string
    {
        return match ($this->family) {
            'debian' => '/etc/apache2/conf-available/plainshow.conf',
            'fedora' => '/etc/httpd/conf.d/plainshow.conf',
            'arch' => '/etc/httpd/conf/extra/plainshow.conf',
            'suse' => '/etc/apache2/conf.d/plainshow.conf',
        };
    }

    public function apacheLogDir(): string
    {
        return match ($this->family) {
            'debian', 'suse' => '/var/log/apache2',
            'fedora', 'arch' => '/var/log/httpd',
        };
    }

    public function mariadbService(): string
    {
        return 'mariadb';
    }

    /** The command that adds a CA certificate to the system's trust store. */
    public function trustCommand(string $certificate): array
    {
        return match ($this->family) {
            'debian' => ['sh', '-c', 'cp '.escapeshellarg($certificate).' /usr/local/share/ca-certificates/plainshow-local-ca.crt && update-ca-certificates'],
            'fedora' => ['sh', '-c', 'cp '.escapeshellarg($certificate).' /etc/pki/ca-trust/source/anchors/plainshow-local-ca.crt && update-ca-trust'],
            'arch' => ['trust', 'anchor', '--store', $certificate],
            'suse' => ['sh', '-c', 'cp '.escapeshellarg($certificate).' /etc/pki/trust/anchors/plainshow-local-ca.crt && update-ca-certificates'],
        };
    }
}
