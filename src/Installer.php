<?php

declare(strict_types=1);

namespace PlainShow\Install;

use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * The installer's conversation: questions first, then every step with a
 * spinner, then where to sign in. `--answers=file.json` answers everything
 * from a file instead (for tests and scripted installs).
 */
final class Installer
{
    private const HOST = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    private State $state;

    private Distro $distro;

    public function __construct(private readonly array $options)
    {
        $this->state = new State;
        $this->distro = Distro::fromBootstrap();
    }

    public function run(): int
    {
        intro(' PlainShow ');
        if (! empty($this->options['answers'])) {
            $answers = json_decode((string) file_get_contents($this->options['answers']), true);
            if (! is_array($answers)) {
                error('The answers file is not valid JSON.');

                return 1;
            }
            $this->state->setAnswers($this->complete($answers));
        } elseif ($this->state->hasAnswers() && ! $this->state->done('checks')) {
            info('An earlier install of '.$this->state->answer('domain').' stopped before it was finished.');
            if (! confirm('Continue it with the same answers?', true)) {
                $this->state->setAnswers($this->ask());
            } elseif (! $this->state->done('admin') && empty($this->state->answer('admin')['password'] ?? null)) {
                $this->state->setAnswers($this->askPassword($this->state->answers()));
            }
        } elseif ($this->state->done('checks')) {
            info('PlainShow is already installed on '.$this->state->answer('domain').'. Updates keeps it current: Administration, Updates.');
            if (! confirm('Run the installer again with the same answers (repairs files it wrote)?', false)) {
                return 0;
            }
            $this->state->setAnswers($this->askPassword($this->state->answers()));
        } else {
            $this->state->setAnswers($this->ask());
        }

        $steps = new Steps($this->distro, $this->state);
        $plan = $steps->plan();
        $count = count($plan);
        $number = 0;
        foreach ($plan as $step => $label) {
            $number++;
            $prefix = "[$number/$count] ";
            if ($this->state->done($step) && ! in_array($step, ['checks'], true)) {
                note($prefix.$label.': done');
                continue;
            }
            Shell::log("== $step: $label");
            try {
                spin(fn () => $steps->run($step), $prefix.$label.'…');
            } catch (Throwable $error) {
                Shell::log('FAILED: '.$error->getMessage());
                error($prefix.$label.' failed: '.$error->getMessage());
                if ($error instanceof CommandFailed && $error->tail !== '') {
                    note($error->tail);
                }
                warning('Everything is written to '.Shell::LOG.'. Fix the problem and run the installer again: it continues from this step.');

                return 1;
            }
            $this->state->markDone($step);
            info($prefix.$label.': done');
        }

        $this->summary();

        return 0;
    }

    /** The questions, in order. */
    private function ask(): array
    {
        note('This installs PlainShow on '.$this->distro->name().": a dashboard and a single sign-in, and the apps you choose, each on its own address under your domain. It takes 10 to 40 minutes.\n\nBefore you start, point your domain and the subdomains below at this server (DNS A or AAAA records), and open ports 80 and 443 to it.");

        $domain = strtolower(trim(text(
            label: 'Your domain',
            placeholder: 'example.com',
            hint: 'The dashboard lives here; the apps on subdomains of it.',
            validate: fn ($value) => preg_match(self::HOST, strtolower(trim($value))) ? null : 'Enter a domain name such as example.com.',
        )));

        $apps = multiselect(
            label: 'Which apps do you want? The dashboard and Login are always installed.',
            options: Apps::extensions(),
            default: ['cloud', 'latex', 'blog', 'split'],
            scroll: 6,
            hint: 'Space selects or clears an app, Enter goes on.',
        );

        $hosts = [];
        foreach (['core', 'login', ...$apps] as $app) {
            $hosts[$app] = Apps::defaultHost($app, $domain);
        }
        table(['App', 'Address'], array_map(fn ($app) => [Apps::name($app), 'https://'.$hosts[$app]], array_keys($hosts)));
        if (! confirm('Use these addresses?', true, hint: 'Choose No to give any app another address.')) {
            foreach (array_keys($hosts) as $app) {
                $hosts[$app] = strtolower(trim(text(
                    label: Apps::name($app).'\'s address',
                    default: $hosts[$app],
                    validate: fn ($value) => preg_match(self::HOST, strtolower(trim($value))) ? null : 'Enter a host name such as cloud.example.com.',
                )));
            }
            if (count(array_unique($hosts)) !== count($hosts)) {
                error('Two apps cannot share an address. Start again.');

                return $this->ask();
            }
        }

        $tls = select(
            label: 'Certificates for https',
            options: [
                'letsencrypt' => 'Let\'s Encrypt (free; this server must be reachable from the internet on port 80)',
                'selfsigned' => 'A certificate of this server\'s own (for a test or a home network; browsers warn until you trust it)',
            ],
            default: 'letsencrypt',
        );
        $email = $tls === 'letsencrypt' ? trim(text(
            label: 'E-mail address for Let\'s Encrypt',
            hint: 'Let\'s Encrypt writes here when a certificate is about to expire and was not renewed.',
            validate: fn ($value) => filter_var(trim($value), FILTER_VALIDATE_EMAIL) ? null : 'Enter an e-mail address.',
        )) : null;
        if ($tls === 'letsencrypt') {
            $this->checkDns($hosts);
        }

        $tex = in_array('latex', $apps, true) ? select(
            label: 'How much of TeX Live should LaTeX have?',
            options: ['basic' => 'The basics (about 500 MB)', 'medium' => 'The common packages and fonts (about 2 GB)', 'full' => 'Everything (about 6 GB)'],
            default: 'medium',
        ) : null;

        note('Now your own account. It is the first administrator: you sign in with it everywhere, and create the other accounts from the dashboard.');
        $admin = [
            'username' => trim(text(
                label: 'Username',
                default: 'admin',
                validate: fn ($value) => preg_match('/^[A-Za-z0-9_.-]{2,32}$/', trim($value)) ? null : '2 to 32 letters, digits, dots, dashes or underscores.',
            )),
            'first' => trim(text(label: 'First name', required: true)),
            'last' => trim(text(label: 'Last name')),
            'email' => trim(text(
                label: 'E-mail address (optional)',
                validate: fn ($value) => trim($value) === '' || filter_var(trim($value), FILTER_VALIDATE_EMAIL) ? null : 'Enter an e-mail address, or leave it empty.',
            )) ?: null,
        ];
        $answers = $this->askPassword(['domain' => $domain, 'apps' => array_values($apps), 'hosts' => $hosts, 'tls' => $tls, 'email' => $email, 'tex' => $tex, 'admin' => $admin]);

        table(['', ''], [
            ['Domain', $domain],
            ['Apps', implode(', ', array_map([Apps::class, 'name'], ['core', 'login', ...$apps]))],
            ['Certificates', $tls === 'letsencrypt' ? "Let's Encrypt ($email)" : 'Self-signed'],
            ['Administrator', $admin['username']],
        ]);
        if (! confirm('Install PlainShow now?', true)) {
            return $this->ask();
        }

        return $answers;
    }

    private function askPassword(array $answers): array
    {
        while (true) {
            $password = password(
                label: 'Password for '.($answers['admin']['username'] ?? 'the administrator'),
                hint: 'At least 10 characters. You can turn on two-factor sign-in after the install.',
                validate: fn ($value) => strlen($value) >= 10 ? null : 'Use at least 10 characters.',
            );
            if (password(label: 'The same password again') === $password) {
                break;
            }
            error('The two passwords are not the same.');
        }
        $answers['admin']['password'] = $password;

        return $answers;
    }

    /** Fills in the defaults of an answers file. */
    private function complete(array $answers): array
    {
        $answers['domain'] = strtolower((string) ($answers['domain'] ?? ''));
        if (! preg_match(self::HOST, $answers['domain'])) {
            throw new \InvalidArgumentException('The answers file needs a domain.');
        }
        $answers['apps'] = array_values(array_intersect((array) ($answers['apps'] ?? []), array_keys(Apps::extensions())));
        foreach (['core', 'login', ...$answers['apps']] as $app) {
            $answers['hosts'][$app] ??= Apps::defaultHost($app, $answers['domain']);
        }
        $answers['tls'] = ($answers['tls'] ?? 'letsencrypt') === 'selfsigned' ? 'selfsigned' : 'letsencrypt';
        $answers['tex'] ??= 'medium';

        return $answers;
    }

    /** Warns when an address does not point at this server yet (Let's Encrypt would fail). */
    private function checkDns(array $hosts): void
    {
        $ours = array_filter([trim((string) @file_get_contents('https://api.ipify.org', false, stream_context_create(['http' => ['timeout' => 5]]))), trim((string) @file_get_contents('https://api64.ipify.org', false, stream_context_create(['http' => ['timeout' => 5]])))]);
        if (! $ours) {
            return;
        }
        $wrong = [];
        foreach ($hosts as $host) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            $addresses = array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records));
            if (! array_intersect($addresses, $ours)) {
                $wrong[] = $host.($addresses ? ' ('.implode(', ', $addresses).')' : ' (no address)');
            }
        }
        if ($wrong) {
            warning('These addresses do not point at this server ('.implode(', ', $ours).') yet: '.implode(', ', $wrong).'. Let\'s Encrypt needs them to. DNS changes can take a while to spread.');
            if (! confirm('Continue anyway?', false)) {
                exit(1);
            }
        }
    }

    private function summary(): void
    {
        $hosts = (array) $this->state->answer('hosts');
        $rows = [];
        foreach ($hosts as $app => $host) {
            $rows[] = [Apps::name($app), "https://$host", (string) $this->state->version($app)];
        }
        table(['App', 'Address', 'Version'], $rows);
        $admin = (string) ($this->state->answer('admin')['username'] ?? 'admin');
        $notes = "Sign in at https://{$hosts['core']} as $admin.\n\n"
            ."Updates: the dashboard looks for new releases every six hours (Administration, Updates) and installs them when you choose Install.\n"
            ."Your Cloud account is ready; phones and computers connect to https://".($hosts['cloud'] ?? '')." with the Nextcloud apps.\n";
        if (! isset($hosts['cloud'])) {
            $notes = preg_replace('/^Your Cloud account.*\n/m', '', $notes);
        }
        if ($this->state->answer('tls') === 'selfsigned') {
            $notes .= "\nThe certificates are this server's own. Browsers trust them once you import ".Steps::TLS."/ca.crt as a certificate authority.\n";
        }
        $notes .= "\nThe install log is ".Shell::LOG.'.';
        note($notes);
        outro(' PlainShow is ready ');
    }
}
