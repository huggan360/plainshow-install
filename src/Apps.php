<?php

declare(strict_types=1);

namespace PlainShow\Install;

/**
 * The PlainShow apps the installer knows. Core (the dashboard) and Login are
 * always installed; the others are extensions to choose from. Release apps
 * are built from a source checkout into /srv/plainshow/<app>/releases by
 * their own deploy script; checkout apps are served from a git checkout that
 * Updates moves forward.
 */
final class Apps
{
    public const GITHUB = 'https://github.com/huggan360/';

    public const ALL = [
        'core' => ['name' => 'Dashboard', 'repo' => 'plainshow-core', 'kind' => 'release', 'sub' => '', 'about' => 'The dashboard, accounts, groups and roles, and Updates.'],
        'login' => ['name' => 'Login', 'repo' => 'plainshow-login', 'kind' => 'release', 'sub' => 'login', 'about' => 'The one sign-in for every app, with two-factor sign-in.'],
        'cloud' => ['name' => 'Cloud', 'repo' => 'plainshow-cloud', 'kind' => 'checkout', 'sub' => 'cloud', 'about' => 'Files, photos and calendar, with phone and computer sync (Nextcloud).'],
        'latex' => ['name' => 'LaTeX', 'repo' => 'plainshow-latex', 'kind' => 'checkout', 'sub' => 'latex', 'about' => 'Write LaTeX projects together, live.'],
        'blog' => ['name' => 'Blog', 'repo' => 'plainshow-blog', 'kind' => 'release', 'sub' => 'blog', 'about' => 'Posts, albums, stories and films (in Swedish).'],
        'split' => ['name' => 'Split', 'repo' => 'plainshow-split', 'kind' => 'release', 'sub' => 'split', 'about' => 'Share expenses in groups (in Swedish).'],
    ];

    /** The extensions to choose from, id => label. */
    public static function extensions(): array
    {
        $list = [];
        foreach (self::ALL as $id => $app) {
            if (! in_array($id, ['core', 'login'], true)) {
                $list[$id] = $app['name'].': '.$app['about'];
            }
        }

        return $list;
    }

    public static function name(string $id): string
    {
        return self::ALL[$id]['name'];
    }

    public static function repo(string $id): string
    {
        return self::GITHUB.self::ALL[$id]['repo'].'.git';
    }

    /** The newest release tag (vX.Y.Z) of an app on GitHub. */
    public static function latest(string $id): string
    {
        $output = Shell::run(['git', 'ls-remote', '--tags', '--refs', self::repo($id), 'v*'], timeout: 120);
        $tags = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (preg_match('#refs/tags/(v\d+\.\d+\.\d+)$#', $line, $match)) {
                $tags[] = $match[1];
            }
        }
        usort($tags, fn ($a, $b) => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));
        if (! $tags) {
            throw new \RuntimeException('No release of '.self::name($id).' was found on GitHub.');
        }

        return $tags[0];
    }

    /** The default address of an app on a domain: the dashboard on the domain, the others on subdomains. */
    public static function defaultHost(string $id, string $domain): string
    {
        $sub = self::ALL[$id]['sub'];

        return $sub === '' ? $domain : "$sub.$domain";
    }
}
