<?php

declare(strict_types=1);

namespace PlainShow\Install;

use RuntimeException;

/**
 * The installation, one step at a time. Every step can run again: it checks
 * what is there and only does what is missing, so a failed install continues
 * where it stopped. Folders on the server:
 *
 *   /srv/plainshow/src/<app>      git checkouts the release apps are built from
 *   /srv/plainshow/<app>          releases, shared state and `current` (core, login, blog, split)
 *   /srv/plainshow/latex, cloud   the checkout apps; cloud-data is Cloud's files
 *   /etc/plainshow                platform.json (no secrets), Apache and TLS files
 *
 * The `plainshow` user owns the code and runs Updates; each Laravel app and
 * Split run as their own user, Blog, LaTeX and Cloud as the web server's.
 */
final class Steps
{
    public const SRV = '/srv/plainshow';

    public const SRC = self::SRV.'/src';

    public const DEPLOY_USER = 'plainshow';

    public const DEPLOY_HOME = '/var/lib/plainshow';

    public const PLATFORM = '/etc/plainshow/platform.json';

    public const TLS = '/etc/plainshow/tls';

    private const DATABASES = [
        'core' => ['plainshow', 'ps_core'],
        'login' => ['plainshow', 'ps_login'],
        'split' => ['plainshow_split', 'ps_split'],
        'blog' => ['plainshow_blog', 'ps_blog'],
        'latex' => ['plainshow_latex', 'ps_latex'],
        'cloud' => ['plainshow_cloud', 'ps_cloud'],
    ];

    /** Apps that run as their own user; the others run as the web server's user. */
    private const OWN_USER = ['core', 'login', 'split'];

    public function __construct(private readonly Distro $distro, private readonly State $state)
    {
    }

    /** @return array<string, string> step id => what the screen says while it runs */
    public function plan(): array
    {
        $steps = [
            'packages' => 'Installing packages (Apache, PHP '.$this->distro->php.', MariaDB'.($this->has('latex') ? ', TeX Live' : '').')',
            'runtime' => 'Installing Node.js and Composer',
            'accounts' => 'Creating the system users',
            'database' => 'Creating the databases',
            'platform' => 'Writing the platform file',
            'sources' => 'Fetching the newest release of every app',
            'web' => 'Setting up Apache and PHP-FPM',
            'certificates' => $this->answer('tls') === 'letsencrypt' ? 'Getting certificates from Let\'s Encrypt' : 'Making a self-signed certificate',
            'login' => 'Installing Login',
            'core' => 'Installing the dashboard',
        ];
        foreach (['split' => 'Split', 'blog' => 'Blog', 'latex' => 'LaTeX', 'cloud' => 'Cloud (this takes a while)'] as $app => $label) {
            if ($this->has($app)) {
                $steps[$app] = "Installing $label";
            }
        }
        $steps['grants'] = 'Connecting the apps\' databases';
        $steps['admin'] = 'Creating your administrator account';
        $steps['updates'] = 'Turning on automatic updates and background jobs';
        $steps['checks'] = 'Checking every site';

        return $steps;
    }

    public function run(string $step): void
    {
        match ($step) {
            'packages' => $this->packages(),
            'runtime' => $this->runtime(),
            'accounts' => $this->accounts(),
            'database' => $this->database(),
            'platform' => $this->platform(),
            'sources' => $this->sources(),
            'web' => $this->web(),
            'certificates' => $this->certificates(),
            'login' => $this->login(),
            'core' => $this->core(),
            'split' => $this->split(),
            'blog' => $this->blog(),
            'latex' => $this->latex(),
            'cloud' => $this->cloud(),
            'grants' => $this->grants(),
            'admin' => $this->admin(),
            'updates' => $this->updates(),
            'checks' => $this->checks(),
        };
    }

    /* ---------- Helpers ---------- */

    private function answer(string $key, mixed $default = null): mixed
    {
        return $this->state->answer($key, $default);
    }

    private function has(string $app): bool
    {
        return in_array($app, ['core', 'login'], true) || in_array($app, (array) $this->answer('apps', []), true);
    }

    /** @return list<string> every app this install has, core and login first */
    private function apps(): array
    {
        return array_values(array_filter(array_keys(Apps::ALL), fn ($app) => $this->has($app)));
    }

    private function host(string $app): string
    {
        return (string) ($this->answer('hosts')[$app] ?? Apps::defaultHost($app, (string) $this->answer('domain')));
    }

    private function url(string $app): string
    {
        return 'https://'.$this->host($app);
    }

    private function web(): void
    {
        $this->webServer();
    }

    private function webUser(): string
    {
        return $this->distro->webUser();
    }

    private function webGroup(): string
    {
        $info = posix_getpwnam($this->webUser());

        return $info ? (posix_getgrgid($info['gid'])['name'] ?? $this->webUser()) : $this->webUser();
    }

    private function php(): string
    {
        return is_file('/usr/bin/php'.$this->distro->php) ? '/usr/bin/php'.$this->distro->php : '/usr/bin/php';
    }

    /** Runs a command as the plainshow user, with its own home for Composer and npm. */
    private function asDeploy(array $command, ?string $cwd = null, array $env = [], ?string $input = null, int $timeout = 3600): string
    {
        return Shell::run($command, $cwd, [
            'HOME' => self::DEPLOY_HOME, 'USER' => self::DEPLOY_USER,
            'PATH' => '/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin',
            'COMPOSER_HOME' => self::DEPLOY_HOME.'/.composer', 'GIT_TERMINAL_PROMPT' => '0',
        ] + $env, $input, self::DEPLOY_USER, $timeout);
    }

    private function asWeb(array $command, ?string $cwd = null, ?string $input = null, int $timeout = 3600): string
    {
        return Shell::run($command, $cwd, ['HOME' => '/tmp', 'PATH' => '/usr/local/bin:/usr/bin:/bin'], $input, $this->webUser(), $timeout);
    }

    private function install(array $packages, int $timeout = 3600): void
    {
        if ($packages) {
            Shell::run($this->distro->installCommand($packages), timeout: $timeout);
        }
    }

    private function systemctl(string ...$arguments): void
    {
        Shell::run(['systemctl', ...$arguments]);
    }

    private function mkdir(string $path, string $owner, string $group, int $mode = 0755): void
    {
        if (! is_dir($path)) {
            mkdir($path, $mode, true);
        }
        chmod($path, $mode);
        chown($path, $owner);
        chgrp($path, $group);
    }

    /** A PHP config file returning an array, for the plain PHP apps. */
    private function phpConfig(string $comment, array $values): string
    {
        return "<?php\n// $comment Written by plainshow-install; not in git.\nreturn ".var_export($values, true).";\n";
    }

    /** Sets keys in a Laravel .env file, keeping everything else from the example. */
    private function envFile(string $example, array $values): string
    {
        $lines = is_file($example) ? (preg_split('/\R/', (string) file_get_contents($example)) ?: []) : [];
        $seen = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^([A-Z0-9_]+)=/', $line, $match) && array_key_exists($match[1], $values)) {
                $lines[$index] = $match[1].'='.$this->envValue($values[$match[1]]);
                $seen[$match[1]] = true;
            }
        }
        foreach ($values as $key => $value) {
            if (! isset($seen[$key])) {
                $lines[] = $key.'='.$this->envValue($value);
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    private function envValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return preg_match('/^[A-Za-z0-9_.:\/@+=-]+$/', $value) ? $value : '"'.addcslashes($value, '"\\$').'"';
    }

    private function sql(string $sql, ?string $database = null): string
    {
        return Shell::run(array_filter(['mariadb', '--batch', '--skip-column-names', $database]), input: $sql);
    }

    /* ---------- Packages and runtimes ---------- */

    private function packages(): void
    {
        if ($this->distro->family === 'debian') {
            Shell::run(['apt-get', 'update', '-q']);
        }
        if ($this->distro->family === 'arch') {
            Shell::run(['pacman', '-Sy', '--noconfirm']);
        }
        $this->install([...$this->distro->basePackages(), ...$this->distro->phpPackages()]);
        if ($this->answer('tls') === 'letsencrypt') {
            $this->install($this->distro->certbotPackages());
        }
        if ($this->has('cloud')) {
            foreach ($this->distro->redisCandidates() as [$package, $service]) {
                if (Shell::ok($this->distro->hasPackageCommand($package))) {
                    $this->install([$package]);
                    $this->state->setSecret('redis_service', $service);
                    break;
                }
            }
        }
        if ($this->has('latex')) {
            $this->install($this->distro->texPackages((string) $this->answer('tex', 'medium')), 3 * 3600);
        }
        $this->phpSettings();
    }

    /** The extensions Arch and openSUSE leave off, and OPcache and APCu settings for Cloud. */
    private function phpSettings(): void
    {
        $ini = "; Written by plainshow-install.\n";
        if ($this->distro->family === 'arch') {
            // install.sh turned a few on for itself; they are all here now, once.
            @unlink('/etc/php/conf.d/00-plainshow-installer.ini');
            foreach (['bcmath', 'bz2', 'exif', 'gd', 'gmp', 'iconv', 'intl', 'mysqli', 'pdo_mysql', 'sodium', 'zip', 'apcu', 'igbinary', 'redis', 'imagick', 'sysvsem', 'pdo_sqlite', 'sqlite3'] as $extension) {
                if (is_file("/usr/lib/php/modules/$extension.so")) {
                    $ini .= "extension=$extension\n";
                }
            }
        }
        // Command line PHP (Updates, Cloud's background jobs) needs more than some distributions' 128 MB; the pools set their own.
        $ini .= "memory_limit=1G\nopcache.enable=1\nopcache.interned_strings_buffer=32\nopcache.max_accelerated_files=20000\nopcache.memory_consumption=256\nopcache.save_comments=1\nopcache.revalidate_freq=60\napc.enable_cli=1\n";
        $dirs = match ($this->distro->family) {
            'debian' => ["/etc/php/{$this->distro->php}/fpm/conf.d", "/etc/php/{$this->distro->php}/cli/conf.d"],
            'fedora' => ['/etc/php.d'],
            'arch' => ['/etc/php/conf.d'],
            'suse' => ['/etc/php8/conf.d'],
        };
        foreach ($dirs as $dir) {
            Shell::write("$dir/90-plainshow.ini", $ini);
        }
        if ($this->distro->family === 'suse' && ! is_file('/etc/php8/fpm/php-fpm.conf') && is_file('/etc/php8/fpm/php-fpm.conf.default')) {
            copy('/etc/php8/fpm/php-fpm.conf.default', '/etc/php8/fpm/php-fpm.conf');
        }
    }

    private function runtime(): void
    {
        // Node.js 22 from nodejs.org, checked against its published checksums, when the system has none new enough.
        $version = Shell::exists('node') ? trim(Shell::run(['node', '-v'])) : '';
        if (! preg_match('/^v(\d+)\.(\d+)/', $version, $match) || (int) $match[1] < 20 || ((int) $match[1] === 20 && (int) $match[2] < 19)) {
            $arch = php_uname('m') === 'aarch64' ? 'arm64' : 'x64';
            $sums = Shell::run(['curl', '-fsSL', 'https://nodejs.org/dist/latest-v22.x/SHASUMS256.txt']);
            if (! preg_match('/^([0-9a-f]{64})\s+(node-v22\.[\d.]+-linux-'.$arch.'\.tar\.xz)$/m', $sums, $file)) {
                throw new RuntimeException('Could not find Node.js 22 for this computer on nodejs.org.');
            }
            $tarball = '/tmp/'.$file[2];
            Shell::run(['curl', '-fsSL', '-o', $tarball, 'https://nodejs.org/dist/latest-v22.x/'.$file[2]]);
            if (hash_file('sha256', $tarball) !== $file[1]) {
                throw new RuntimeException('The Node.js download did not match its checksum.');
            }
            Shell::run(['rm', '-rf', '/opt/plainshow/node']);
            Shell::run(['mkdir', '-p', '/opt/plainshow/node']);
            Shell::run(['tar', '-xJf', $tarball, '-C', '/opt/plainshow/node', '--strip-components=1']);
            foreach (['node', 'npm', 'npx'] as $program) {
                @unlink("/usr/local/bin/$program");
                symlink("/opt/plainshow/node/bin/$program", "/usr/local/bin/$program");
            }
            @unlink($tarball);
        }
        if (! Shell::exists('composer')) {
            throw new RuntimeException('Composer is missing; run install.sh again.');
        }
    }

    /* ---------- Users and databases ---------- */

    private function accounts(): void
    {
        $nologin = is_file('/usr/sbin/nologin') ? '/usr/sbin/nologin' : '/sbin/nologin';
        if (! posix_getpwnam(self::DEPLOY_USER)) {
            Shell::run(['useradd', '--system', '--home-dir', self::DEPLOY_HOME, '--create-home', '--shell', '/bin/bash', '--comment', 'PlainShow (code and updates)', self::DEPLOY_USER]);
        }
        $this->mkdir(self::DEPLOY_HOME, self::DEPLOY_USER, self::DEPLOY_USER, 0750);
        foreach (self::OWN_USER as $app) {
            $user = "plainshow-$app";
            if ($this->has($app) && ! posix_getpwnam($user)) {
                Shell::run(['useradd', '--system', '--no-create-home', '--home-dir', '/nonexistent', '--shell', $nologin, '--comment', 'PlainShow '.Apps::name($app), $user]);
            }
        }
        // The plainshow user may read what the apps write (logs) and reach the web server's files.
        foreach ([$this->webGroup(), ...array_map(fn ($app) => "plainshow-$app", array_filter(self::OWN_USER, fn ($app) => $this->has($app)))] as $group) {
            Shell::run(['usermod', '-aG', $group, self::DEPLOY_USER]);
        }

        $this->mkdir('/etc/plainshow', 'root', 'root', 0755);
        $this->mkdir(self::SRV, 'root', 'root', 0755);
        $this->mkdir(self::SRC, self::DEPLOY_USER, self::DEPLOY_USER, 0755);
        foreach (['core', 'login', 'blog', 'split'] as $app) {
            if ($this->has($app)) {
                // The app's own user (the web server's for the blog) reads its shared config and storage.
                $group = $app === 'blog' ? $this->webGroup() : "plainshow-$app";
                $this->mkdir(self::SRV."/$app", self::DEPLOY_USER, self::DEPLOY_USER, 0755);
                $this->mkdir(self::SRV."/$app/shared", self::DEPLOY_USER, $group, 0750);
            }
        }

        // What the deploy scripts and Updates do with sudo (they install software, so this is
        // as much trust as root; it is spelled out to show what is used).
        $commands = [];
        foreach (['setfacl', 'chown', 'chmod', 'chgrp', 'find', 'install', 'cp', 'cmp', 'systemctl', 'apachectl', 'apache2ctl', 'a2enmod', 'a2enconf'] as $program) {
            foreach (['/usr/bin', '/usr/sbin', '/bin', '/sbin'] as $dir) {
                if (is_file("$dir/$program")) {
                    $commands[] = "$dir/$program";
                }
            }
        }
        $php = $this->php();
        $sudoers = "# PlainShow: what Updates (the plainshow user) may do as root and as the web server. Written by plainshow-install.\n"
            ."Defaults:".self::DEPLOY_USER." !requiretty, !pam_acct_mgmt\n"
            .self::DEPLOY_USER.' ALL=(root) NOPASSWD: '.implode(', ', array_unique($commands))."\n"
            .self::DEPLOY_USER.' ALL=('.$this->webUser().') NOPASSWD: '.implode(', ', array_unique([$php, '/usr/bin/php']))."\n";
        Shell::write('/etc/sudoers.d/90-plainshow', $sudoers, 0440);
        Shell::run(['visudo', '-cf', '/etc/sudoers.d/90-plainshow']);
    }

    private function database(): void
    {
        $service = $this->distro->mariadbService();
        if ($this->distro->family === 'arch' && ! is_dir('/var/lib/mysql/mysql')) {
            Shell::run(['mariadb-install-db', '--user=mysql', '--basedir=/usr', '--datadir=/var/lib/mysql']);
        }
        $this->systemctl('enable', '--now', $service);
        $sql = '';
        $created = [];
        foreach (self::DATABASES as $app => [$database, $user]) {
            if (! $this->has($app)) {
                continue;
            }
            $password = $this->state->secret("db_$user", 40);
            if (! isset($created[$database])) {
                $sql .= "CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
                $created[$database] = true;
            }
            $sql .= "CREATE USER IF NOT EXISTS '$user'@'localhost' IDENTIFIED BY '$password';\n"
                ."ALTER USER '$user'@'localhost' IDENTIFIED BY '$password';\n"
                ."GRANT ALL PRIVILEGES ON `$database`.* TO '$user'@'localhost';\n";
        }
        $this->sql($sql."FLUSH PRIVILEGES;\n");
    }

    /** Grants between apps' databases, once their tables exist. */
    private function grants(): void
    {
        $sql = '';
        if ($this->has('split')) {
            $sql .= "GRANT SELECT (`id`, `username`, `first_name`, `last_name`, `avatar_path`, `phone`, `role`, `status`, `updated_at`) ON `plainshow`.`users` TO 'ps_split'@'localhost';\n";
        }
        if ($this->has('blog') && $this->has('split')) {
            foreach (['groups', 'members', 'expenses'] as $table) {
                $sql .= "GRANT SELECT ON `plainshow_split`.`$table` TO 'ps_blog'@'localhost';\n";
            }
        }
        if ($sql !== '') {
            $this->sql($sql."FLUSH PRIVILEGES;\n");
        }
    }

    /* ---------- The platform file and the code ---------- */

    private function platform(): void
    {
        $urls = [];
        foreach ($this->apps() as $app) {
            $urls[$app] = $this->url($app);
        }
        $platform = [
            'domain' => $this->answer('domain'),
            'urls' => $urls,
            'system' => [
                'web_user' => $this->webUser(),
                'web_service' => $this->distro->webService(),
                'php_fpm_service' => $this->distro->fpmService(),
                'php_fpm_pool_dir' => $this->distro->fpmPoolDir(),
                'fpm_socket_dir' => $this->distro->socketDir(),
                'apache_include_dir' => Templates::APACHE_DIR,
                'deploy_user' => self::DEPLOY_USER,
                'sources' => self::SRC,
                'cloud_dir' => self::SRV.'/cloud',
                'latex_dir' => self::SRV.'/latex',
                'latex_websocket' => 'systemd',
                'blog_uploads' => self::SRV.'/blog-uploads',
            ],
        ];
        Shell::write(self::PLATFORM, json_encode($platform, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", 0644);

        // Every app on this server reaches the others here, without going out to the internet.
        $hosts = (string) file_get_contents('/etc/hosts');
        $block = "# PlainShow apps on this server (plainshow-install)\n127.0.0.1 ".implode(' ', array_map(fn ($app) => $this->host($app), $this->apps()))."\n# End of PlainShow\n";
        $hosts = preg_replace("/# PlainShow apps on this server \(plainshow-install\)\n.*?# End of PlainShow\n/s", '', $hosts) ?? $hosts;
        // Written in place: /etc/hosts can be a mount of its own (containers) that cannot be replaced.
        file_put_contents('/etc/hosts', rtrim($hosts)."\n".$block);
    }

    private function sources(): void
    {
        foreach ($this->apps() as $app) {
            $tag = $this->state->version($app) ?? Apps::latest($app);
            $dir = match ($app) {
                'latex', 'cloud' => self::SRV."/$app",
                default => self::SRC."/$app",
            };
            if (! is_dir("$dir/.git")) {
                if (! is_dir(dirname($dir))) {
                    mkdir(dirname($dir), 0755, true);
                }
                if (in_array($app, ['latex', 'cloud'], true)) {
                    $this->mkdir($dir, self::DEPLOY_USER, $this->webGroup(), 02775);
                }
                $this->asDeploy(['git', '-c', 'advice.detachedHead=false', 'clone', '--quiet', '--branch', $tag, Apps::repo($app), $dir], timeout: 3600);
            } else {
                $this->asDeploy(['git', 'fetch', '--quiet', '--tags', 'origin'], $dir);
                $this->asDeploy(['git', '-c', 'advice.detachedHead=false', 'checkout', '--quiet', $tag], $dir);
            }
            $this->state->setVersion($app, $tag);
        }
    }

    /* ---------- Apache, PHP-FPM and certificates ---------- */

    private function certificate(): ?array
    {
        if ($this->answer('tls') === 'letsencrypt') {
            $dir = '/etc/letsencrypt/live/plainshow';

            return is_file("$dir/fullchain.pem") ? ["$dir/fullchain.pem", "$dir/privkey.pem"] : null;
        }

        return is_file(self::TLS.'/plainshow.crt') ? [self::TLS.'/plainshow.crt', self::TLS.'/plainshow.key'] : null;
    }

    private function webServer(): void
    {
        $distro = $this->distro;
        $this->mkdir(Templates::ACME, 'root', 'root', 0755);
        $this->mkdir(Templates::APACHE_DIR, 'root', 'root', 0755);
        $this->mkdir(self::SRV.'/errors', 'root', 'root', 0755);
        $this->mkdir(self::SRV.'/updating', self::DEPLOY_USER, self::DEPLOY_USER, 0755);

        // Fedora's mod_ssl makes its localhost certificate on Apache's first start; configtest needs it before.
        if ($distro->family === 'fedora' && ! is_file('/etc/pki/tls/certs/localhost.crt')) {
            Shell::run(['openssl', 'req', '-x509', '-nodes', '-newkey', 'rsa:2048', '-days', '3650', '-subj', '/CN=localhost',
                '-keyout', '/etc/pki/tls/private/localhost.key', '-out', '/etc/pki/tls/certs/localhost.crt']);
            chmod('/etc/pki/tls/private/localhost.key', 0600);
        }
        // Arch's Apache has no Listen 443 of its own; the others bring it with mod_ssl.
        Shell::write(Templates::APACHE_DIR.'/00-plainshow.conf', Templates::apacheBase($distro, $distro->family === 'arch'));
        Shell::write($distro->apacheIncludeFile(), Templates::header('include PlainShow\'s Apache files').'IncludeOptional '.Templates::APACHE_DIR."/*.conf\n");
        switch ($distro->family) {
            case 'debian':
                Shell::run(['a2dismod', '-q', '-f', 'mpm_prefork', 'mpm_worker']);
                foreach (glob('/etc/apache2/mods-enabled/php*.load') ?: [] as $module) {
                    Shell::run(['a2dismod', '-q', '-f', basename($module, '.load')]);
                }
                Shell::run(['a2enmod', '-q', 'mpm_event', 'ssl', 'rewrite', 'headers', 'proxy', 'proxy_fcgi', 'proxy_http', 'proxy_wstunnel', 'setenvif', 'env', 'mime', 'dir', 'alias', 'socache_shmcb']);
                Shell::run(['a2enconf', '-q', 'plainshow']);
                break;
            case 'arch':
                $conf = (string) file_get_contents('/etc/httpd/conf/httpd.conf');
                if (! str_contains($conf, 'conf/extra/plainshow.conf')) {
                    file_put_contents('/etc/httpd/conf/httpd.conf', rtrim($conf)."\n\n# PlainShow\nInclude conf/extra/plainshow.conf\n");
                }
                break;
            case 'suse':
                foreach (['ssl', 'rewrite', 'headers', 'proxy', 'proxy_fcgi', 'proxy_http', 'proxy_wstunnel', 'setenvif', 'env', 'mime', 'dir', 'alias', 'socache_shmcb'] as $module) {
                    Shell::run(['a2enmod', $module]);
                }
                Shell::run(['a2enflag', 'SSL']);
                break;
        }

        // PHP-FPM: one pool per app.
        $pools = [];
        foreach ($this->apps() as $app) {
            [$user, $group] = in_array($app, self::OWN_USER, true) ? ["plainshow-$app", "plainshow-$app"] : [$this->webUser(), $this->webGroup()];
            $pools[] = $app;
            Shell::write($distro->fpmPoolDir()."/plainshow-$app.conf", Templates::pool($app, $user, $group, $this->webUser(), $this->webGroup(), $distro->socketDir(), $app === 'cloud'));
        }
        $this->systemctl('enable', $distro->fpmService());
        $this->systemctl('restart', $distro->fpmService());

        $this->sites();
        $this->systemctl('enable', $distro->webService());
        Shell::run(['apachectl', 'configtest']);
        $this->systemctl('restart', $distro->webService());

        $this->firewall();
    }

    /** One Apache site per app; the https part once there is a certificate. */
    private function sites(): void
    {
        $certificate = $this->certificate();
        foreach ($this->apps() as $app) {
            $root = match ($app) {
                'latex', 'cloud' => self::SRV."/$app",
                default => self::SRV."/$app/current/public",
            };
            $extra = match ($app) {
                'latex' => "ProxyPreserveHost On\nProxyPass \"/ws\" \"ws://127.0.0.1:8080/\" retry=0 timeout=60\nProxyPassReverse \"/ws\" \"ws://127.0.0.1:8080/\"",
                'cloud' => "ProxyPreserveHost On\nRequestHeader set X-Forwarded-Proto \"https\"\nProxyPass /push/ws ws://127.0.0.1:7867/ws\nProxyPass /push/ http://127.0.0.1:7867/\nProxyPassReverse /push/ http://127.0.0.1:7867/",
                default => '',
            };
            Shell::write(Templates::APACHE_DIR."/site-$app.conf", Templates::site($app, $this->host($app), $root, $app, $this->distro->socketDir(), $this->distro->apacheLogDir(), $certificate, $extra));
        }
    }

    private function firewall(): void
    {
        if (Shell::ok(['systemctl', 'is-active', '--quiet', 'firewalld'])) {
            Shell::run(['firewall-cmd', '--permanent', '--add-service=http', '--add-service=https']);
            Shell::run(['firewall-cmd', '--reload']);
        } elseif (Shell::exists('ufw') && str_contains(Shell::run(['ufw', 'status']), 'Status: active')) {
            Shell::run(['ufw', 'allow', '80,443/tcp']);
        }
        if (Shell::exists('getenforce') && trim(Shell::run(['getenforce'])) === 'Enforcing') {
            // Apache proxies to the websocket servers and PHP talks to the database and Redis.
            Shell::run(['setsebool', '-P', 'httpd_can_network_connect', '1', 'httpd_can_network_connect_db', '1', 'httpd_unified', '1', 'httpd_execmem', '1']);
            Shell::ok(['semanage', 'fcontext', '-a', '-t', 'httpd_sys_rw_content_t', self::SRV.'(/.*)?']);
            Shell::ok(['semanage', 'fcontext', '-a', '-t', 'httpd_sys_content_t', Templates::ACME.'(/.*)?']);
            Shell::run(['restorecon', '-R', self::SRV, Templates::ACME]);
        }
    }

    private function certificates(): void
    {
        $hosts = array_map(fn ($app) => $this->host($app), $this->apps());
        if ($this->answer('tls') === 'letsencrypt') {
            Shell::run(['certbot', 'certonly', '--webroot', '-w', Templates::ACME, '--cert-name', 'plainshow', '--expand', '--keep-until-expiring',
                '--non-interactive', '--agree-tos', '-m', (string) $this->answer('email'),
                '--deploy-hook', 'systemctl reload '.$this->distro->webService(),
                ...array_merge(...array_map(fn ($host) => ['-d', $host], $hosts))], timeout: 900);
        } else {
            $this->selfSigned($hosts);
        }
        $this->sites();
        Shell::run(['apachectl', 'configtest']);
        $this->systemctl('reload', $this->distro->webService());
    }

    /** A local certificate authority, trusted by this server, and one certificate for every address. */
    private function selfSigned(array $hosts): void
    {
        $dir = self::TLS;
        $this->mkdir($dir, 'root', 'root', 0755);
        if (! is_file("$dir/ca.crt")) {
            Shell::run(['openssl', 'req', '-x509', '-new', '-nodes', '-newkey', 'rsa:3072', '-days', '3650', '-subj', '/CN=PlainShow local CA ('.$this->answer('domain').')', '-keyout', "$dir/ca.key", '-out', "$dir/ca.crt"]);
            chmod("$dir/ca.key", 0600);
        }
        $names = implode(',', array_map(fn ($host) => "DNS:$host", $hosts));
        if (! is_file("$dir/plainshow.crt") || ! str_contains(Shell::run(['openssl', 'x509', '-in', "$dir/plainshow.crt", '-noout', '-ext', 'subjectAltName']), 'DNS:'.end($hosts))) {
            Shell::write("$dir/plainshow.ext", "subjectAltName=$names\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n");
            Shell::run(['openssl', 'req', '-new', '-nodes', '-newkey', 'rsa:2048', '-subj', '/CN='.$hosts[0], '-keyout', "$dir/plainshow.key", '-out', "$dir/plainshow.csr"]);
            Shell::run(['openssl', 'x509', '-req', '-in', "$dir/plainshow.csr", '-CA', "$dir/ca.crt", '-CAkey', "$dir/ca.key", '-CAcreateserial', '-days', '825', '-sha256', '-extfile', "$dir/plainshow.ext", '-out', "$dir/plainshow.crt"]);
            chmod("$dir/plainshow.key", 0600);
        }
        Shell::run($this->distro->trustCommand("$dir/ca.crt"));
    }

    /* ---------- Login and the dashboard ---------- */

    private function laravelEnv(string $app, array $values): void
    {
        $file = self::SRV."/$app/shared/.env";
        $existing = is_file($file) ? (string) file_get_contents($file) : '';
        if (! preg_match('/^APP_KEY=base64:/m', $existing)) {
            $values['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        }
        Shell::write($file, $this->envFile($existing !== '' ? $file : self::SRC."/$app/.env.example", $values), 0640, self::DEPLOY_USER, "plainshow-$app");
    }

    private function artisan(string $app, array $arguments, ?string $input = null): string
    {
        return $this->asDeploy([$this->php(), 'artisan', ...$arguments], self::SRV."/$app/current", input: $input);
    }

    private function deployRelease(string $app): void
    {
        $tag = (string) $this->state->version($app);
        $current = self::SRV."/$app/current";
        if (is_link($current) && str_ends_with((string) readlink($current), "-$tag")) {
            return;
        }
        if (in_array($app, ['core', 'login'], true)) {
            $this->asDeploy(['bash', self::SRC.'/core/ops/deploy.sh', $app, self::SRC."/$app", $tag], self::SRC.'/core', timeout: 3600);
        } else {
            $this->asDeploy(['bash', 'ops/deploy.sh', $tag], self::SRC."/$app", timeout: 3600);
        }
    }

    private function login(): void
    {
        [$database, $user] = self::DATABASES['login'];
        $this->laravelEnv('login', [
            'APP_URL' => $this->url('login'), 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_LEVEL' => 'warning',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => $database, 'DB_USERNAME' => $user,
            'DB_PASSWORD' => $this->state->secret("db_$user", 40),
            'PLAINSHOW_CORE_URL' => $this->url('core'), 'PLAINSHOW_INTERNAL_SECRET' => $this->state->secret('internal'),
            'PLAINSHOW_PROTECTED_PI_USERNAME' => '',
        ]);
        $this->deployRelease('login');

        $storage = self::SRV.'/login/shared/storage';
        if (! is_file("$storage/oauth-private.key")) {
            $this->artisan('login', ['passport:keys', '--force']);
        }
        foreach (glob("$storage/oauth-*.key") ?: [] as $key) {
            chgrp($key, 'plainshow-login');
            chmod($key, 0640);
        }

        // The personal access client behind PlainShow API tokens.
        if (! $this->state->hasSecret('personal_client_secret')) {
            $output = $this->artisan('login', ['passport:client', '--personal', '--name=PlainShow API tokens', '--provider=users', '--no-interaction']);
            if (! preg_match('/Client ID\W+(\S+)/i', $output, $id) || ! preg_match('/Client secret\W+(\S+)/i', $output, $secret)) {
                throw new RuntimeException('Login did not report its API token client.');
            }
            $this->state->setSecret('personal_client_id', $id[1]);
            $this->state->setSecret('personal_client_secret', $secret[1]);
        }
        $this->laravelEnv('login', [
            'PASSPORT_PERSONAL_ACCESS_CLIENT_ID' => $this->state->secret('personal_client_id'),
            'PASSPORT_PERSONAL_ACCESS_CLIENT_SECRET' => $this->state->secret('personal_client_secret'),
        ]);

        // One sign-in client per app.
        foreach ($this->apps() as $app) {
            if ($app === 'login') {
                continue;
            }
            $base = $this->url($app);
            [$redirects, $logout] = match ($app) {
                'cloud' => [["$base/index.php/apps/user_oidc/code", "$base/apps/user_oidc/code"], "$base/index.php/apps/plainshow/auth/frontchannel"],
                default => [["$base/auth/callback"], "$base/auth/frontchannel"],
            };
            $rotate = $this->state->hasSecret("client_{$app}_secret") ? [] : ['--rotate'];
            $output = $this->artisan('login', ['plainshow:client', $app, Apps::name($app) === 'Dashboard' ? 'PlainShow' : Apps::name($app), ...$redirects, "--logout=$logout", ...$rotate]);
            if (preg_match('/^client_id=(\S+)/m', $output, $id)) {
                $this->state->setSecret("client_{$app}_id", $id[1]);
            }
            if (preg_match('/^client_secret=(\S+)/m', $output, $secret)) {
                $this->state->setSecret("client_{$app}_secret", $secret[1]);
            }
        }
        $this->artisan('login', ['config:cache']);
    }

    private function core(): void
    {
        [$database, $user] = self::DATABASES['core'];
        $this->laravelEnv('core', [
            'APP_URL' => $this->url('core'), 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_LEVEL' => 'warning',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => $database, 'DB_USERNAME' => $user,
            'DB_PASSWORD' => $this->state->secret("db_$user", 40),
            'PLAINSHOW_LOGIN_URL' => $this->url('login'),
            'PLAINSHOW_CLIENT_ID' => $this->state->secret('client_core_id'), 'PLAINSHOW_CLIENT_SECRET' => $this->state->secret('client_core_secret'),
            'PLAINSHOW_INTERNAL_SECRET' => $this->state->secret('internal'),
            'PLAINSHOW_PROJECTS' => self::SRC, 'PLAINSHOW_PROTECTED_PI_USERNAME' => '',
        ]);
        $this->deployRelease('core');

        // The secret each extension and the dashboard share for widgets and signed API calls.
        $secrets = [];
        foreach ($this->apps() as $app) {
            if (! in_array($app, ['core', 'login'], true)) {
                $secrets[$app] = $this->state->secret("widget_$app");
            }
        }
        Shell::write(self::SRV.'/core/shared/storage/app/widget-secrets.json', json_encode($secrets, JSON_PRETTY_PRINT)."\n", 0640, self::DEPLOY_USER, 'plainshow-core');
        $this->artisan('core', ['plainshow:extensions']);
        $this->artisan('core', ['config:cache']);
    }

    private function admin(): void
    {
        $admin = (array) $this->answer('admin', []);
        $exists = trim($this->sql("SELECT COUNT(*) FROM users WHERE username = '".addslashes((string) ($admin['username'] ?? ''))."'", 'plainshow'));
        if ($exists === '0') {
            if (empty($admin['password'])) {
                throw new RuntimeException('The administrator\'s password is no longer known; run the installer again to choose it.');
            }
            $arguments = ['plainshow:admin', (string) $admin['username']];
            foreach (['first' => 'first', 'last' => 'last', 'email' => 'email'] as $key => $option) {
                if (! empty($admin[$key])) {
                    $arguments[] = "--$option=".$admin[$key];
                }
            }
            $this->artisan('core', $arguments, (string) $admin['password']);
        }
        if ($this->has('cloud')) {
            $this->cloudAdmin((string) $admin['username']);
        }
        // The password is not kept once the account exists.
        $answers = $this->state->answers();
        unset($answers['admin']['password']);
        $this->state->setAnswers($answers);
    }

    /* ---------- The extensions ---------- */

    private function split(): void
    {
        [$database, $user] = self::DATABASES['split'];
        $config = self::SRV.'/split/shared/config';
        $this->mkdir($config, self::DEPLOY_USER, 'plainshow-split', 0750);
        $this->mkdir(self::SRV.'/split/shared/storage', self::DEPLOY_USER, 'plainshow-split', 02770);
        foreach (['cache', 'logs', 'sessions'] as $dir) {
            $this->mkdir(self::SRV."/split/shared/storage/$dir", self::DEPLOY_USER, 'plainshow-split', 02770);
        }
        $write = fn (string $name, string $content) => Shell::write("$config/$name.php", $content, 0640, self::DEPLOY_USER, 'plainshow-split');
        $write('database', $this->phpConfig('Split\'s database.', ['host' => 'localhost', 'user' => $user, 'password' => $this->state->secret("db_$user", 40), 'database' => $database, 'accounts' => 'plainshow']));
        $write('plainshow', $this->phpConfig('PlainShow sign-in for Split.', ['client_id' => $this->state->secret('client_split_id'), 'client_secret' => $this->state->secret('client_split_secret'), 'extension' => 'split', 'cache_dir' => self::SRV.'/split/shared/storage/cache']));
        $write('widgets', $this->phpConfig('The secret Split shares with the dashboard.', ['secret' => $this->state->secret('widget_split')]));
        if ($this->has('blog')) {
            $write('notify', $this->phpConfig('Notices to the blog.', ['secret' => $this->state->secret('notify')]));
        }
        $this->deployRelease('split');
    }

    private function blog(): void
    {
        [$database, $user] = self::DATABASES['blog'];
        $group = $this->webGroup();
        $config = self::SRV.'/blog/shared/config';
        $this->mkdir($config, self::DEPLOY_USER, $group, 0750);
        foreach (['app', 'app/sessions', 'app/cache', 'app/storage'] as $dir) {
            $this->mkdir(self::SRV."/blog/shared/$dir", self::DEPLOY_USER, $group, 02770);
        }
        $uploads = self::SRV.'/blog-uploads';
        foreach (['', '/assets', '/assets/vids', '/assets/profile_pictures', '/sand'] as $dir) {
            $this->mkdir($uploads.$dir, $this->webUser(), $group, 02775);
        }
        $write = fn (string $name, string $content) => Shell::write("$config/$name.php", $content, 0640, self::DEPLOY_USER, $group);
        $write('database', $this->phpConfig('The blog\'s database.', ['host' => 'localhost', 'user' => $user, 'password' => $this->state->secret("db_$user", 40), 'database' => $database]));
        $write('plainshow', $this->phpConfig('PlainShow sign-in for the blog.', ['client_id' => $this->state->secret('client_blog_id'), 'client_secret' => $this->state->secret('client_blog_secret'), 'extension' => 'blog', 'cache_dir' => self::SRV.'/blog/shared/app/storage']));
        $write('widgets', $this->phpConfig('The secret the blog shares with the dashboard.', ['secret' => $this->state->secret('widget_blog')]));
        $write('notify', $this->phpConfig('Notices from other apps (Split).', ['secret' => $this->state->secret('notify'), 'sources' => $this->has('split') ? ['split'] : []]));
        $this->deployRelease('blog');
    }

    private function latex(): void
    {
        [$database, $user] = self::DATABASES['latex'];
        $dir = self::SRV.'/latex';
        $group = $this->webGroup();
        $this->mkdir("$dir/config", self::DEPLOY_USER, $group, 0750);
        foreach (['temp', 'sessions'] as $writable) {
            $this->mkdir("$dir/$writable", $this->webUser(), $group, 02770);
        }
        $write = fn (string $name, string $content) => Shell::write("$dir/config/$name.php", $content, 0640, self::DEPLOY_USER, $group);
        $write('database', $this->phpConfig('LaTeX\'s database.', ['host' => 'localhost', 'user' => $user, 'password' => $this->state->secret("db_$user", 40), 'database' => $database]));
        $write('plainshow', $this->phpConfig('PlainShow sign-in for LaTeX.', ['client_id' => $this->state->secret('client_latex_id'), 'client_secret' => $this->state->secret('client_latex_secret'), 'extension' => 'latex', 'cache_dir' => "$dir/sessions", 'ws_ticket_secret' => $this->state->secret('latex_ws')]));
        $write('widgets', $this->phpConfig('The secret LaTeX shares with the dashboard.', ['secret' => $this->state->secret('widget_latex')]));
        $this->asDeploy(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress'], $dir);
        $this->asDeploy([$this->php(), 'bin/migrate.php'], $dir);
        Shell::run(['chmod', '-R', 'g+rX', $dir]);

        Shell::write('/etc/systemd/system/plainshow-latex-websocket.service', Templates::service('PlainShow LaTeX live editing', $this->webUser(), $this->php().' websocket/server.php', $dir, true, ['PLAINSHOW_WS_PORT' => '8080', 'HOME' => '/tmp']));
        $this->systemctl('daemon-reload');
        $this->systemctl('enable', 'plainshow-latex-websocket');
        $this->systemctl('restart', 'plainshow-latex-websocket');
    }

    private function occ(array $arguments, array $env = []): string
    {
        return Shell::run([$this->php(), '-d', 'memory_limit=1G', 'occ', '--no-interaction', ...$arguments], self::SRV.'/cloud', ['HOME' => '/tmp', 'PATH' => '/usr/local/bin:/usr/bin:/bin'] + $env, null, $this->webUser());
    }

    private function cloud(): void
    {
        [$database, $user] = self::DATABASES['cloud'];
        $dir = self::SRV.'/cloud';
        $data = self::SRV.'/cloud-data';
        $web = $this->webUser();
        $group = $this->webGroup();
        $this->asDeploy(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--working-dir', 'apps/plainshow'], $dir);
        // The code is the plainshow user's; the web server writes config/ and custom_apps/ (group-writable).
        Shell::run(['chown', '-R', self::DEPLOY_USER.":$group", $dir]);
        Shell::run(['chmod', '-R', 'g+rwX', $dir]);
        $this->mkdir("$dir/custom_apps", self::DEPLOY_USER, $group, 02775);
        $this->mkdir($data, $web, $group, 0770);

        $installed = str_contains($this->occ(['status', '--output=json']), '"installed":true');
        if (! $installed) {
            $this->occ(['maintenance:install', '--database=mysql', '--database-host=localhost', "--database-name=$database", "--database-user=$user",
                '--database-pass='.$this->state->secret("db_$user", 40), '--admin-user=cloud-admin', '--admin-pass='.$this->state->secret('cloud_admin', 40),
                "--data-dir=$data"]);
        }
        $host = $this->host('cloud');
        $set = fn (string $key, string $value, string $type = 'string') => $this->occ(['config:system:set', $key, "--value=$value", "--type=$type"]);
        $this->occ(['config:system:set', 'trusted_domains', '0', "--value=$host"]);
        $set('overwrite.cli.url', "https://$host");
        $set('overwriteprotocol', 'https');
        // Cloud reaches Login on this machine (/etc/hosts), not through the internet: home
        // routers often cannot reach their own public address.
        $set('dns_pinning', 'false', 'boolean');
        $set('allow_local_remote_servers', 'true', 'boolean');
        // Apache and the push server talk to Cloud from this machine.
        $this->occ(['config:system:set', 'trusted_proxies', '0', '--value=127.0.0.1']);
        $this->occ(['config:system:set', 'trusted_proxies', '1', '--value=::1']);
        $set('htaccess.RewriteBase', '/');
        $set('memcache.local', '\\OC\\Memcache\\APCu');
        $set('maintenance_window_start', '1', 'integer');
        if ($this->state->hasSecret('redis_service')) {
            $this->systemctl('enable', '--now', $this->state->secret('redis_service'));
            $this->occ(['config:system:set', 'redis', 'host', '--value=127.0.0.1']);
            $this->occ(['config:system:set', 'redis', 'port', '--value=6379', '--type=integer']);
            $set('memcache.locking', '\\OC\\Memcache\\Redis');
            $set('memcache.distributed', '\\OC\\Memcache\\Redis');
        }
        $this->occ(['maintenance:update:htaccess']);
        // Cloud has its own list of trusted authorities; a self-signed install adds its own.
        if ($this->answer('tls') !== 'letsencrypt' && is_file(self::TLS.'/ca.crt')) {
            $this->occ(['security:certificates:import', self::TLS.'/ca.crt']);
        }

        // Sign-in through PlainShow; push for the phone and desktop apps. Both come from Nextcloud's app store.
        foreach (['user_oidc', 'notify_push'] as $app) {
            if (! is_dir("$dir/custom_apps/$app") && ! is_dir("$dir/apps/$app")) {
                $this->occ(['app:install', $app]);
            }
            $this->occ(['app:enable', $app]);
        }
        $this->occ(['app:enable', 'plainshow']);
        $this->occ(['background:cron']);
        $login = $this->url('login');
        $this->occ(['user_oidc:provider', 'PlainShow', '--clientid='.$this->state->secret('client_cloud_id'), '--clientsecret-env=PLAINSHOW_CLIENT_SECRET', "--discoveryuri=$login/.well-known/openid-configuration",
            '--scope=openid email profile plainshow', '--unique-uid=0', '--mapping-uid=ps_cloud_uid', '--mapping-display-name=name', '--mapping-email=email', '--mapping-avatar=picture',
            '--mapping-groups=ps_groups', '--group-provisioning=1', '--group-whitelist-regex=^ps-', '--send-id-token-hint=1'], ['PLAINSHOW_CLIENT_SECRET' => $this->state->secret('client_cloud_secret')]);
        $this->occ(['config:app:set', 'user_oidc', 'allow_multiple_user_backends', '--value=0']);
        $this->occ(['config:app:set', 'plainshow', 'widget_secret', '--value='.$this->state->secret('widget_cloud'), '--lazy']);
        $this->occ(['config:system:set', 'user_oidc', 'soft_auto_provision', '--value=true', '--type=boolean']);
        $set('token_auth_enforced', 'true', 'boolean');
        $set('defaultapp', 'plainshow');
        $this->occ(['theming:config', 'name', 'PlainShow Cloud']);
        $this->occ(['theming:config', 'url', $this->url('core')]);
        $this->occ(['theming:config', 'primary_color', '#FC5000']);
        $this->occ(['theming:config', 'background', 'backgroundColor']);
        $this->occ(['theming:config', 'background_color', '#151515']);
        foreach (['logo' => 'plainshow-icon.png', 'logoheader' => 'plainshow-logo.png', 'favicon' => 'plainshow-icon.png'] as $key => $file) {
            if (is_file("$dir/apps/plainshow/img/$file")) {
                $this->occ(['theming:config', $key, "$dir/apps/plainshow/img/$file"]);
            }
        }
        // Like the first PlainShow: no app store, no updater; Updates moves Cloud forward.
        $set('appstoreenabled', 'false', 'boolean');
        $set('updatechecker', 'false', 'boolean');
        $set('upgrade.disable-web', 'true', 'boolean');
        $set('integrity.check.disabled', 'true', 'boolean');

        // notify_push runs beside Apache.
        $arch = php_uname('m') === 'aarch64' ? 'aarch64' : 'x86_64';
        $binary = "$dir/custom_apps/notify_push/bin/$arch/notify_push";
        Shell::run(['chmod', '0755', $binary]);
        $push = ['PORT' => '7867', 'NEXTCLOUD_URL' => "https://$host"];
        if ($this->answer('tls') !== 'letsencrypt') {
            $push['ALLOW_SELF_SIGNED'] = 'true';
        }
        Shell::write('/etc/systemd/system/notify_push.service', Templates::service('Nextcloud push for PlainShow Cloud', $web, "$binary $dir/config/config.php", $dir, true, $push));
        $this->systemctl('daemon-reload');
        $this->systemctl('enable', 'notify_push');
        $this->systemctl('restart', 'notify_push');
        sleep(2);
        Shell::ok([$this->php(), '-d', 'memory_limit=1G', 'occ', 'notify_push:setup', "https://$host/push"], "$dir", $web);

        // Cloud accounts follow the dashboard's: core asks a small root helper to create and disable them.
        $core = self::SRV.'/core/current/ops';
        Shell::run(['install', '-D', '-o', 'root', '-g', 'root', '-m', '0755', "$core/account-helper.py", '/usr/local/libexec/plainshow-account-helper']);
        Shell::write('/etc/sudoers.d/92-plainshow-account-helper', (string) file_get_contents("$core/account-helper.sudoers"), 0440);
        Shell::run(['visudo', '-cf', '/etc/sudoers.d/92-plainshow-account-helper']);
    }

    /** The first admin is Cloud's admin too: the account exists before the first sign-in and joins the admin group. */
    private function cloudAdmin(string $username): void
    {
        $exists = Shell::ok([$this->php(), '-d', 'memory_limit=1G', 'occ', 'user:info', $username], self::SRV.'/cloud', $this->webUser());
        if (! $exists) {
            Shell::run([$this->php(), '-d', 'memory_limit=1G', 'occ', '--no-interaction', 'user:add', '--password-from-env', $username], self::SRV.'/cloud', ['OC_PASS' => Shell::secret(40), 'HOME' => '/tmp'], null, $this->webUser());
        }
        Shell::ok([$this->php(), '-d', 'memory_limit=1G', 'occ', 'group:adduser', 'admin', $username], self::SRV.'/cloud', $this->webUser());
    }

    /* ---------- Background jobs and checks ---------- */

    private function updates(): void
    {
        $php = $this->php();
        $logs = self::SRV.'/core/shared/storage/logs/updates.log';
        $env = ['HOME' => self::DEPLOY_HOME, 'PATH' => '/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin', 'COMPOSER_HOME' => self::DEPLOY_HOME.'/.composer'];
        $units = [
            'plainshow-updates' => ['PlainShow Updates: install queued updates', self::DEPLOY_USER, "/bin/sh -c '$php artisan plainshow:updates --run >> $logs 2>&1'", self::SRV.'/core/current', '*-*-* *:*:00'],
            'plainshow-updates-check' => ['PlainShow Updates: look for new releases', self::DEPLOY_USER, "/bin/sh -c '$php artisan plainshow:updates --check >> $logs 2>&1'", self::SRV.'/core/current', '*-*-* 00/6:17:00'],
        ];
        if ($this->has('cloud')) {
            $units['plainshow-cloud-cron'] = ['PlainShow Cloud background jobs', $this->webUser(), "$php -f ".self::SRV.'/cloud/cron.php', self::SRV.'/cloud', 'every 5min'];
        }
        if ($this->answer('tls') === 'letsencrypt') {
            $units['plainshow-certbot'] = ['Renew PlainShow\'s certificates', 'root', 'certbot renew --quiet', '/', '*-*-* 03,15:41:00'];
        }
        foreach ($units as $name => [$description, $user, $command, $directory, $schedule]) {
            Shell::write("/etc/systemd/system/$name.service", Templates::service($description, $user, $command, $directory, false, $user === self::DEPLOY_USER ? $env : []));
            Shell::write("/etc/systemd/system/$name.timer", Templates::timer($description, $schedule));
        }
        $this->systemctl('daemon-reload');
        foreach (array_keys($units) as $name) {
            $this->systemctl('enable', '--now', "$name.timer");
        }
        // Updates knows the installed versions from the start.
        $this->artisan('core', ['plainshow:updates', '--check']);
    }

    private function checks(): void
    {
        $paths = ['core' => '/up', 'login' => '/up', 'blog' => '/api/v1/health', 'split' => '/api/v1/health', 'latex' => '/auth/frontchannel', 'cloud' => '/index.php/apps/plainshow/api/v1/health'];
        $failed = [];
        foreach ($this->apps() as $app) {
            $host = $this->host($app);
            $curl = curl_init("https://$host".$paths[$app]);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_RESOLVE => ["$host:443:127.0.0.1"]]);
            curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            Shell::log("health $app: $status ".curl_error($curl));
            curl_close($curl);
            if ($status !== 200) {
                $failed[] = Apps::name($app)." ($status)";
            }
        }
        if ($failed) {
            throw new RuntimeException('These sites did not answer as they should: '.implode(', ', $failed).'.');
        }
    }
}
