<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Copies a site into a new, empty website of the same node. The source is only read, as its
 * own user; the clone is written as its own user. Data travels through a pipe between the two:
 * root never opens a file of a site, where a symbolic link could point anywhere.
 */
final class SiteCloner
{
    /** The config of the source would point the clone to the database of the source. */
    public const NOT_COPIED = ['./wp-config.php', './wp-cli.yml', './wp-cli.local.yml', './.maintenance', './wp-content/cache'];

    public function __construct(
        private readonly Config $config,
        private readonly Crypto $crypto,
        private readonly PathGuard $guard,
    ) {
    }

    /**
     * @param bool $resume an earlier attempt of the same job wrote into the web root of the clone: every
     *                     step runs again over those files (see wpm-worker.php)
     * @return list<string> executed commands and what the search-replace found
     */
    public function clone(array $target, array $source, ?Runner $runner = null, bool $resume = false): array
    {
        $this->assertCloneable($target, $source);
        $dbPassword = $this->crypto->decrypt((string)$target['secret_db']);
        $runner ??= new Runner($this->config->isDryRun(), [$dbPassword], 3600);

        $sourceRoot = rtrim((string)$source['document_root'], '/');
        $targetRoot = rtrim((string)$target['document_root'], '/');
        $sourceWeb = $this->guard->assertInside($sourceRoot . '/web');
        $targetWeb = $this->guard->assertInside($targetRoot . '/web');
        if (!is_file($sourceWeb . '/wp-config.php')) {
            throw new \RuntimeException("{$source['domain']} has no WordPress to clone");
        }
        if (!$resume && !$this->guard->isPristineWebRoot($targetWeb)) {
            throw new \RuntimeException("Refusing to install: {$targetWeb} is not empty: the module never installs over existing files");
        }

        $sourceUser = (string)$source['system_user'];
        $targetUser = (string)$target['system_user'];
        $from = new WpCli($this->config->get('wp_cli'), $sourceWeb, $sourceUser);
        $to = new WpCli($this->config->get('wp_cli'), $targetWeb, $targetUser);

        $step = static function (string $what, callable $do) {
            try {
                return $do();
            } catch (\RuntimeException $e) {
                throw new \RuntimeException(FailureMessage::step($what, $e->getMessage()), 0, $e);
            }
        };
        [$oldUrl, $prefix] = $step("Reading the settings of {$source['domain']}", static fn(): array => [
            self::safeUrl(trim($runner->mustRun($from->optionGet('home'))), (string)$source['domain']),
            self::tablePrefix($runner->mustRun($from->configGet('table_prefix'))),
        ]);

        foreach (['index.html', 'standard_index.html'] as $placeholder) {
            $runner->mustRun(new Command(['rm', '-f', '--', "{$targetWeb}/{$placeholder}"], $targetUser));
        }

        $exclude = array_map(static fn(string $path): string => "--exclude={$path}", self::NOT_COPIED);
        $step('Copy of the files', static fn() => $runner->pipe(
            new Command(array_merge(['tar', '-cz'], $exclude, ['-C', $sourceWeb, '.']), $sourceUser),
            new Command(['tar', '-xz', '-C', $targetWeb], $targetUser),
        ));

        $step('Creation of wp-config.php', static function () use ($runner, $to, $target, $dbPassword, $prefix, $resume, $targetWeb, $targetUser): void {
            $runner->mustRun($to->configCreate((string)$target['db_name'], (string)$target['db_user'], $dbPassword, (string)$target['db_host'], $prefix, $resume));
            $runner->mustRun(new Command(['chmod', '0640', '--', "{$targetWeb}/wp-config.php"], $targetUser));
        });

        // Every write below goes to the database named here: stop if it is not the one of the clone.
        $database = $runner->mustRun($to->configGet('DB_NAME'));
        if (!$runner->isDryRun()) {
            self::assertOwnDatabase($database, (string)$target['db_name'], (string)$source['db_name']);
        }

        $step('Copy of the database', static fn() => $runner->pipe($from->dbExport('-'), $to->dbImport('-')));

        $newUrl = (string)($target['site_url'] ?? '') ?: 'http://' . $target['domain'];
        $plan = new SearchReplacePlan($oldUrl, $newUrl, $sourceRoot, $targetRoot);
        $report = [];
        foreach ($plan->rules() as [$pattern, $replacement]) {
            // preg_replace needs its escapes, WP-CLI receives the same text.
            $changed = trim($step('Replacement of the old address', static fn(): string => $runner->mustRun($to->searchReplace($pattern, $replacement))));
            $report[] = "Replaced {$changed} values: {$pattern}";
        }
        $database = new SiteDatabase($this->config, $this->guard);
        $report = array_merge($report, self::replaceInObjects($database, $target, $plan, $runner));

        // Not left to the rules above: a value they cannot match, even a hostile one, must not stay.
        $home = (string)parse_url($oldUrl, PHP_URL_PATH);
        $runner->mustRun($to->optionUpdate('home', $newUrl . rtrim($home, '/')));
        $runner->mustRun($to->optionUpdate('siteurl', $newUrl . rtrim($home, '/')));

        // A clone is public but must not compete with its source in search engines.
        $runner->mustRun($to->optionUpdate('blog_public', '0'));

        // Pages built with the old address, some encoded where no replace reaches.
        $database->query($target, SiteCaches::clearSql($prefix), $runner);

        $left = $this->remaining($to, $plan, $runner);
        // The summary first: the status message keeps only the beginning of a long log.
        return array_merge($report, $left, $runner->log());
    }

    /**
     * The code of the source runs in the account of the target client and, through the group of that
     * client, can read the other sites of the target. Only an admin decides that.
     */
    public static function mayCloneToClient(string $userType, int $sourceClient, int $targetClient): bool
    {
        return $sourceClient === $targetClient || $userType === 'admin';
    }

    /** The home option belongs to the owner of the source: only a real address is used. */
    public static function safeUrl(string $url, string $domain): string
    {
        try {
            new SearchReplacePlan($url, 'http://clone.invalid', '/source', '/target');
            return $url;
        } catch (\InvalidArgumentException) {
            return 'http://' . $domain;
        }
    }

    public static function assertOwnDatabase(string $actual, string $expected, string $source): void
    {
        $actual = trim($actual);
        if ($actual !== $expected || $actual === $source) {
            throw new \RuntimeException("The clone points to the wrong database: {$actual}");
        }
    }

    /**
     * What WP-CLI skipped: objects of plugin classes. Not a reason to stop: what is left is reported.
     *
     * @return list<string>
     */
    public static function replaceInObjects(SiteDatabase $database, array $site, SearchReplacePlan $plan, Runner $runner): array
    {
        try {
            $changed = (new ObjectReplacer($database))->run($site, $plan, $runner);
        } catch (\RuntimeException $e) {
            return ['Warning: the values inside objects of plugins were not checked: ' . $e->getMessage()];
        }
        return ["Replaced {$changed} values inside objects of plugins"];
    }

    /**
     * Lines of `wp db search --one_line`, without guid: it stays as it is on purpose. With a plan, only
     * lines that name the old host itself, not a longer host that ends with it.
     */
    public static function countMentions(string $output, ?SearchReplacePlan $plan = null): int
    {
        return count(self::mentionLines($output, $plan));
    }

    /**
     * Where the lines of `wp db search --one_line` are: "wp_options.option_value (row 12)", the
     * first few, for the admin to find them. Names come from the site: odd ones are not shown.
     */
    public static function mentionPlaces(string $output, ?SearchReplacePlan $plan = null, int $max = 3): string
    {
        $places = [];
        foreach (self::mentionLines($output, $plan) as $line) {
            if (preg_match('/^([A-Za-z0-9_$]{1,64}):([A-Za-z0-9_$]{1,64}):(?:(\d{1,20}):)?/', $line, $m)) {
                $places[] = "{$m[1]}.{$m[2]}" . (isset($m[3]) && $m[3] !== '' ? " (row {$m[3]})" : '');
            }
        }
        $places = array_values(array_unique($places));
        $more = count($places) > $max ? ' and others' : '';
        return implode(', ', array_slice($places, 0, $max)) . $more;
    }

    /** @return list<string> */
    private static function mentionLines(string $output, ?SearchReplacePlan $plan): array
    {
        $lines = array_filter(explode("\n", trim($output)));
        return array_values(array_filter(
            $lines,
            static fn(string $line): bool => !preg_match('/^[^:]+:guid:/', $line) && ($plan === null || $plan->mentions($line)),
        ));
    }

    public static function tablePrefix(string $output): string
    {
        $prefix = trim($output);
        if ($prefix === '') {
            return 'wp_';
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,30}$/', $prefix)) {
            throw new \RuntimeException('Unsafe table prefix in the source site');
        }
        return $prefix;
    }

    private function assertCloneable(array $target, array $source): void
    {
        if ((int)$source['wpm_site_id'] === (int)$target['wpm_site_id']) {
            throw new \RuntimeException('A site cannot be cloned onto itself');
        }
        if (($source['status'] ?? '') !== 'installed') {
            throw new \RuntimeException("{$source['domain']} is not installed");
        }
        if ((int)$source['server_id'] !== (int)$target['server_id']) {
            throw new \RuntimeException("{$source['domain']} is on another node");
        }
        if ((string)$source['db_name'] !== '' && (string)$source['db_name'] === (string)$target['db_name']) {
            throw new \RuntimeException('Source and clone have the same database');
        }
    }

    /**
     * What is left for the admin to look at: mentions of the old domain, which can be correct (an
     * email address, a domain in a text), and base64 values, which no replace can change.
     *
     * @return list<string>
     */
    private function remaining(WpCli $wp, SearchReplacePlan $plan, Runner $runner): array
    {
        $report = [];
        $found = $runner->mustRun($wp->dbSearch($plan->oldHost()));
        $mentions = self::countMentions($found, $plan);
        if ($mentions > 0) {
            $where = self::mentionPlaces($found, $plan);
            $report[] = "Warning: {$mentions} values still mention {$plan->oldHost()}" . ($where === '' ? '' : ", in {$where}")
                . '. They can be right, as email addresses or text: check them.';
        }
        foreach ($plan->base64Needles() as $needle) {
            $found = $runner->mustRun($wp->dbSearch($needle));
            if (self::countMentions($found) > 0) {
                $where = self::mentionPlaces($found);
                $report[] = "Warning: {$plan->oldHost()} seems to be inside a base64 value" . ($where === '' ? '' : ", in {$where}")
                    . ', which cannot be replaced. Check it.';
                break;
            }
        }
        return $report;
    }
}
