<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * Hardening of a site, one switch per measure, each reversible:
 *
 * - file_edit: DISALLOW_FILE_EDIT, no editor of themes and plugins in wp-admin;
 * - uploads_php: no PHP runs from wp-content/uploads (.htaccess there);
 * - sensitive_files: readme.html, license.txt, copies of wp-config.php and .git are not served (.htaccess);
 * - xmlrpc: xmlrpc.php is not served (.htaccess): it stops Jetpack and the mobile app;
 * - user_list: visitors who are not logged in cannot list the users (REST API, ?author=N, the users sitemap, oEmbed),
 *   with a must-use plugin: logged-in users keep the list, which the block editor needs to choose an author.
 *
 * The .htaccess measures are blocks marked with the name of the module, so that the rest of the file, the rules of
 * WordPress included, stays as it is. After turning one on, its own address must answer 403: a web server that
 * ignores .htaccess (nginx) or a block that breaks Apache is undone at once. The site is checked before and after,
 * like an update. Everything runs as the site user.
 */
final class SiteHardening
{
    public const MEASURES = ['file_edit', 'uploads_php', 'sensitive_files', 'xmlrpc', 'user_list'];
    /** For new sites, unless the settings say otherwise. */
    public const DEFAULTS = ['file_edit', 'uploads_php', 'sensitive_files', 'user_list'];

    public const USERS_PLUGIN = 'wpstation-hide-users.php';
    public const USERS_MARKER = 'ispconfig-wpstation hide users';

    private const MARK = 'ispconfig-wpstation';

    private const BLOCKS = [
        'uploads_php' => [
            '<FilesMatch "\.(?i:php[0-9]?|phtml|phar|pht)$">',
            '    Require all denied',
            '</FilesMatch>',
        ],
        'sensitive_files' => [
            '<FilesMatch "^(readme\.html|license\.txt|wp-config\.php[^/]+|\.wp-config\.php\.swp)$">',
            '    Require all denied',
            '</FilesMatch>',
            'RedirectMatch 404 "/\.(git|svn|hg)(/|$)"',
        ],
        'xmlrpc' => [
            '<Files "xmlrpc.php">',
            '    Require all denied',
            '</Files>',
        ],
    ];

    /** What must answer 403 once the measure is on. */
    private const PROBES = [
        'uploads_php' => '/wp-content/uploads/wpstation-hardening-check.php',
        'sensitive_files' => '/wp-config.php.wpstation-hardening-check',
        'xmlrpc' => '/xmlrpc.php',
        // 404 here: the route is gone for visitors. The query form works without pretty permalinks too.
        'user_list' => '/?rest_route=/wp/v2/users',
    ];

    private const EXPECTED = ['user_list' => 404];

    public static function usersPlugin(): string
    {
        return <<<'PHP'
            <?php
            /**
             * Plugin Name: WPStation: hide the users
             * Description: Visitors who are not logged in cannot list the users of the site: REST API, ?author=N, the users sitemap, oEmbed. Change it in the ISPConfig panel, not here.
             */
            // ispconfig-wpstation hide users. WPStation writes and removes this file.
            add_filter('rest_endpoints', static function ($endpoints) {
                if (!is_user_logged_in()) {
                    foreach (array_keys($endpoints) as $route) {
                        if (preg_match('#^/wp/v2/users(/|$)#', (string)$route)) {
                            unset($endpoints[$route]);
                        }
                    }
                }
                return $endpoints;
            });
            add_action('init', static function () {
                if (isset($_GET['author']) && !is_admin() && !is_user_logged_in()) {
                    wp_die('', '', ['response' => 404]);
                }
            });
            add_filter('wp_sitemaps_add_provider', static function ($provider, $name) {
                return $name === 'users' ? false : $provider;
            }, 10, 2);
            add_filter('oembed_response_data', static function ($data) {
                unset($data['author_name'], $data['author_url']);
                return $data;
            });

            PHP;
    }

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
        private readonly HealthCheck $health,
    ) {
    }

    /** @return array<string, bool> each measure, on or off now */
    public function state(array $site, Runner $runner): array
    {
        $state = [];
        foreach (self::MEASURES as $measure) {
            try {
                $state[$measure] = match ($measure) {
                    'file_edit' => WpCli::isTrue($runner->run($this->wp($site)->configGet('DISALLOW_FILE_EDIT'))),
                    'user_list' => $this->mustUse()->has($site, self::USERS_PLUGIN, self::USERS_MARKER, $runner),
                    default => $runner->run(new Command(['grep', '-qF', '--', self::begin($measure), $this->htaccess($site, $measure)], (string)$site['system_user']))['exit'] === 0,
                };
            } catch (\RuntimeException) {
                $state[$measure] = false;
            }
        }
        return $state;
    }

    /** @return array{outcome:string, message:string} outcome: done, refused, rolled_back or rollback_failed */
    public function set(array $site, string $measure, bool $on, ?Runner $runner = null): array
    {
        if (!in_array($measure, self::MEASURES, true)) {
            throw new \InvalidArgumentException("Unknown hardening measure: {$measure}");
        }
        $runner ??= new Runner($this->config->isDryRun(), [], 300);
        $before = $this->health->run($site);
        try {
            $this->apply($site, $measure, $on, $runner);
        } catch (\RuntimeException $e) {
            $reason = str_starts_with($e->getMessage(), 'Not turned ') ? $e->getMessage() : "Changing {$measure} failed: " . $e->getMessage();
            return $this->undo($site, $measure, $on, $before, $runner, $reason);
        }
        if ($on && isset(self::PROBES[$measure]) && !$runner->isDryRun()) {
            sleep(1);
            $status = $this->health->status($site, self::PROBES[$measure]);
            if ($status !== (self::EXPECTED[$measure] ?? 403)) {
                $where = $measure === 'user_list' ? 'WordPress did not load the must-use plugin' : 'the web server does not read .htaccess there';
                $why = $status >= 500 ? "the web server answers {$status} with it" : "it has no effect (HTTP {$status}): {$where}";
                return $this->undo($site, $measure, $on, $before, $runner, "Not turned on: {$why}");
            }
        }
        $broken = $this->health->regressionsAfterChange($site, $before);
        if ($broken !== []) {
            return $this->undo($site, $measure, $on, $before, $runner, 'Broken after the change: ' . $this->health->describe($broken));
        }
        if ($measure === 'user_list') {
            return ['outcome' => 'done', 'message' => $on ? 'The list of users is hidden from visitors.' : 'The list of users is visible again.'];
        }
        return ['outcome' => 'done', 'message' => ucfirst(str_replace('_', ' ', $measure)) . ($on ? ' on.' : ' off.')];
    }

    /**
     * The measures of the settings on a new site. A failure is a warning: the site works without them.
     *
     * @param list<string> $measures
     * @return list<string> warnings
     */
    public function applyDefaults(array $site, array $measures, ?Runner $runner = null): array
    {
        $warnings = [];
        foreach (array_intersect(self::MEASURES, $measures) as $measure) {
            $result = $this->set($site, $measure, true, $runner);
            if ($result['outcome'] !== 'done') {
                $warnings[] = "Warning: hardening {$measure} not applied: {$result['message']}";
            }
        }
        return $warnings;
    }

    /** The file with its block added, after any block of the same measure is taken out. Before the rest: Apache reads it first. */
    public static function withBlock(string $content, string $measure): string
    {
        $rest = ltrim(self::withoutBlock($content, $measure), "\n");
        $block = implode("\n", array_merge([self::begin($measure)], self::BLOCKS[$measure], [self::end($measure)])) . "\n";
        return $rest === '' ? $block : $block . "\n" . $rest;
    }

    public static function withoutBlock(string $content, string $measure): string
    {
        $pattern = '/' . preg_quote(self::begin($measure), '/') . '\n.*?' . preg_quote(self::end($measure), '/') . '\n?\n?/s';
        return (string)preg_replace($pattern, '', $content);
    }

    private function apply(array $site, string $measure, bool $on, Runner $runner): void
    {
        if ($measure === 'user_list') {
            $reason = $this->mustUse()->put($site, self::USERS_PLUGIN, self::USERS_MARKER, $on ? self::usersPlugin() : null, $runner);
            if ($reason !== '') {
                throw new \RuntimeException('Not turned ' . ($on ? 'on' : 'off') . ': ' . $reason);
            }
            return;
        }
        if ($measure === 'file_edit') {
            $wp = $this->wp($site);
            if ($on) {
                $runner->mustRun($wp->configSet('DISALLOW_FILE_EDIT', 'true', true));
            } elseif ($runner->isDryRun() || $runner->run($wp->configHas('DISALLOW_FILE_EDIT'))['exit'] === 0) {
                $runner->mustRun($wp->configDelete('DISALLOW_FILE_EDIT'));
            }
            return;
        }
        $user = (string)$site['system_user'];
        $file = $this->htaccess($site, $measure);
        if ($measure === 'uploads_php' && $on) {
            // WordPress makes it at the first upload, as the same user.
            $runner->mustRun(new Command(['mkdir', '-p', '--', dirname($file)], $user));
        }
        $exists = $runner->run(new Command(['test', '-f', $file], $user))['exit'] === 0;
        $content = $exists && !$runner->isDryRun() ? $runner->mustRun(new Command(['cat', '--', $file], $user)) : '';
        $new = $on ? self::withBlock($content, $measure) : self::withoutBlock($content, $measure);
        if ($new === $content) {
            return;
        }
        if (trim($new) === '' && !$runner->isDryRun()) {
            // Only our block was there: the file goes, as before the module.
            $runner->mustRun(new Command(['rm', '-f', '--', $file], $user));
            return;
        }
        $runner->mustRun(new Command(['tee', '--', $file], $user, null, $new));
    }

    private function undo(array $site, string $measure, bool $on, array $before, Runner $runner, string $reason): array
    {
        try {
            $this->apply($site, $measure, !$on, $runner);
        } catch (\RuntimeException $e) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. Undoing it failed: " . $e->getMessage()];
        }
        $still = $this->health->regressionsAfterChange($site, $before);
        if ($still !== []) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. Undone, but still broken: " . $this->health->describe($still)];
        }
        return ['outcome' => str_starts_with($reason, 'Not turned ') ? 'refused' : 'rolled_back', 'message' => "{$reason}. Undone."];
    }

    private function htaccess(array $site, string $measure): string
    {
        $web = Subfolder::webRoot($site, $this->guard);
        if ($measure !== 'uploads_php') {
            return $web . '/.htaccess';
        }
        // A link could send the file elsewhere: the module writes only inside the web root.
        foreach (['/wp-content', '/wp-content/uploads'] as $folder) {
            if (is_link($web . $folder)) {
                throw new \RuntimeException("{$folder} is a link: not changed");
            }
        }
        return $web . '/wp-content/uploads/.htaccess';
    }

    private static function begin(string $measure): string
    {
        return '# BEGIN ' . self::MARK . ' ' . str_replace('_', '-', $measure);
    }

    private static function end(string $measure): string
    {
        return '# END ' . self::MARK . ' ' . str_replace('_', '-', $measure);
    }

    private function mustUse(): MustUsePlugin
    {
        return new MustUsePlugin($this->config, $this->guard);
    }

    private function wp(array $site): WpCli
    {
        return WpCli::forSite($this->config, $this->guard, $site);
    }
}
