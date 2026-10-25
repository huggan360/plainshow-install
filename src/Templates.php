<?php

declare(strict_types=1);

namespace PlainShow\Install;

/**
 * The configuration files the installer writes: Apache sites, PHP-FPM pools
 * and systemd units. Everything PlainShow adds to Apache lives in
 * /etc/plainshow/apache, which the distribution's Apache includes.
 */
final class Templates
{
    public const APACHE_DIR = '/etc/plainshow/apache';

    public const ACME = '/var/lib/plainshow-acme';

    public static function header(string $what): string
    {
        return "# PlainShow: $what. Written by plainshow-install; it is rewritten when the installer runs again.\n";
    }

    /** Modules and settings every PlainShow site needs, for the distributions without a2enmod. */
    public static function apacheBase(Distro $distro, bool $needsListen): string
    {
        $out = self::header('Apache modules and TLS settings');
        if (in_array($distro->family, ['arch', 'fedora'], true)) {
            foreach (['socache_shmcb', 'ssl', 'rewrite', 'headers', 'proxy', 'proxy_fcgi', 'proxy_http', 'proxy_wstunnel', 'setenvif', 'env', 'mime', 'dir', 'alias'] as $module) {
                $out .= "<IfModule !{$module}_module>\n    LoadModule {$module}_module modules/mod_$module.so\n</IfModule>\n";
            }
        }
        if ($needsListen) {
            $out .= "Listen 443\n";
        }
        $out .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1\nSSLHonorCipherOrder off\nSSLSessionTickets off\n";
        $out .= "ServerTokens Prod\nServerSignature Off\n";
        // Arch's Apache only looks for index.html.
        $out .= "<IfModule dir_module>\n    DirectoryIndex index.php index.html\n</IfModule>\n";

        return $out;
    }

    /** A site: port 80 answers Let's Encrypt and sends everything else to https; 443 serves the app once there is a certificate. */
    public static function site(string $app, string $host, string $root, string $pool, string $socketDir, string $logDir, ?array $certificate, string $extra = ''): string
    {
        $acme = self::ACME;
        $out = self::header(Apps::name($app)." on $host");
        $out .= <<<CONF
<VirtualHost *:80>
    ServerName $host
    Alias /.well-known/acme-challenge/ "$acme/.well-known/acme-challenge/"
    <Directory "$acme/.well-known/acme-challenge">
        Options None
        AllowOverride None
        Require all granted
    </Directory>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [END,NE,R=301]
</VirtualHost>

CONF;
        if ($certificate === null) {
            return $out;
        }
        [$cert, $key] = $certificate;
        $extra = $extra === '' ? '' : rtrim(preg_replace('/^/m', '    ', trim($extra)))."\n";

        return $out.<<<CONF
<VirtualHost *:443>
    ServerName $host
    DocumentRoot "$root"
    <Directory "$root">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:$socketDir/plainshow-$pool.sock|fcgi://plainshow-$pool"
    </FilesMatch>
    SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
    <DirectoryMatch "/\.(?!well-known)">
        Require all denied
    </DirectoryMatch>
$extra    Header always set Strict-Transport-Security "max-age=31536000"
    SSLEngine on
    SSLCertificateFile "$cert"
    SSLCertificateKeyFile "$key"
    ErrorLog "$logDir/plainshow-$app-error.log"
    CustomLog "$logDir/plainshow-$app-access.log" combined
</VirtualHost>

CONF;
    }

    /** A PHP-FPM pool for one app, running as its own user (or the web server's). */
    public static function pool(string $name, string $user, string $group, string $webUser, string $webGroup, string $socketDir, bool $big = false): string
    {
        $memory = $big ? '1G' : '512M';
        $upload = $big ? '16G' : '512M';

        return '; '.ltrim(self::header("PHP-FPM pool $name"), '# ')."[plainshow-$name]
user = $user
group = $group
listen = $socketDir/plainshow-$name.sock
listen.owner = $webUser
listen.group = $webGroup
listen.mode = 0660
pm = ondemand
pm.max_children = ".($big ? 16 : 10)."
pm.process_idle_timeout = 30s
pm.max_requests = 500
catch_workers_output = yes
env[PATH] = /usr/local/bin:/usr/bin:/bin
php_admin_value[memory_limit] = $memory
php_admin_value[upload_max_filesize] = $upload
php_admin_value[post_max_size] = $upload
php_admin_value[max_execution_time] = 3600
php_admin_value[max_input_time] = 3600
php_admin_value[output_buffering] = 0
";
    }

    /** A systemd service, and a timer for it when $every is given (systemd calendar or OnUnitActiveSec). */
    public static function service(string $description, string $user, string $command, string $directory = '/', bool $daemon = false, array $environment = []): string
    {
        $env = '';
        foreach ($environment as $key => $value) {
            $env .= "Environment=$key=$value\n";
        }

        return "# Written by plainshow-install.\n[Unit]\nDescription=$description\nAfter=network-online.target mariadb.service\nWants=network-online.target\n\n[Service]\n"
            .($daemon ? "Type=simple\nRestart=always\nRestartSec=5\n" : "Type=oneshot\n")
            ."User=$user\nWorkingDirectory=$directory\n{$env}ExecStart=$command\n"
            .($daemon ? "\n[Install]\nWantedBy=multi-user.target\n" : '');
    }

    public static function timer(string $description, string $schedule): string
    {
        $when = str_starts_with($schedule, 'every ')
            ? 'OnBootSec='.substr($schedule, 6)."\nOnUnitActiveSec=".substr($schedule, 6)
            : "OnCalendar=$schedule";

        return "# Written by plainshow-install.\n[Unit]\nDescription=$description\n\n[Timer]\n$when\nPersistent=true\nAccuracySec=1s\n\n[Install]\nWantedBy=timers.target\n";
    }
}
