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
    private const NOT_COPIED = ['./wp-config.php', './.maintenance', './wp-content/cache'];

    public function __construct(
        private readonly Config $config,
        private readonly Crypto $crypto,
        private readonly PathGuard $guard,
    ) {
    }

    /** @return list<string> executed commands and what the search-replace found */
    public function clone(array $target, array $source, ?Runner $runner = null): array
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
        if (!$this->guard->isPristineWebRoot($targetWeb)) {
            throw new \RuntimeException("Refusing to install: {$targetWeb} is not empty");
        }

        $sourceUser = (string)$source['system_user'];
        $targetUser = (string)$target['system_user'];
        $from = new WpCli($this->config->get('wp_cli'), $sourceWeb, $sourceUser);
        $to = new WpCli($this->config->get('wp_cli'), $targetWeb, $targetUser);

        $oldUrl = trim($runner->mustRun($from->optionGet('home'))) ?: 'http://' . $source['domain'];
        $prefix = self::tablePrefix($runner->mustRun($from->configGet('table_prefix')));

        foreach (['index.html', 'standard_index.html'] as $placeholder) {
            $runner->mustRun(new Command(['rm', '-f', '--', "{$targetWeb}/{$placeholder}"], $targetUser));
        }

        $exclude = array_map(static fn(string $path): string => "--exclude={$path}", self::NOT_COPIED);
        $runner->pipe(
            new Command(array_merge(['tar', '-cz'], $exclude, ['-C', $sourceWeb, '.']), $sourceUser),
            new Command(['tar', '-xz', '-C', $targetWeb], $targetUser),
        );

        $runner->mustRun($to->configCreate((string)$target['db_name'], (string)$target['db_user'], $dbPassword, (string)$target['db_host'], $prefix));
        $runner->mustRun(new Command(['chmod', '0640', '--', "{$targetWeb}/wp-config.php"], $targetUser));

        // Every write below goes to the database named here: stop if it is not the one of the clone.
        $database = $runner->mustRun($to->configGet('DB_NAME'));
        if (!$runner->isDryRun()) {
            self::assertOwnDatabase($database, (string)$target['db_name'], (string)$source['db_name']);
        }

        $runner->pipe($from->dbExport('-'), $to->dbImport('-'));

        $newUrl = (string)($target['site_url'] ?? '') ?: 'http://' . $target['domain'];
        $plan = new SearchReplacePlan($oldUrl, $newUrl, $sourceRoot, $targetRoot);
        $report = [];
        foreach ($plan->rules() as [$pattern, $replacement]) {
            // preg_replace needs its escapes, WP-CLI receives the same text.
            $changed = trim($runner->mustRun($to->searchReplace($pattern, $replacement)));
            $report[] = "Replaced {$changed} values: {$pattern}";
        }

        $left = $this->remaining($to, $plan, $runner);
        // The summary first: the status message keeps only the beginning of a long log.
        return array_merge($report, $left, $runner->log());
    }

    public static function assertOwnDatabase(string $actual, string $expected, string $source): void
    {
        $actual = trim($actual);
        if ($actual !== $expected || $actual === $source) {
            throw new \RuntimeException("The clone points to the wrong database: {$actual}");
        }
    }

    /** Lines of `wp db search --one_line`, without guid: it stays as it is on purpose. */
    public static function countMentions(string $output): int
    {
        $lines = array_filter(explode("\n", trim($output)));
        return count(array_filter($lines, static fn(string $line): bool => !preg_match('/^[^:]+:guid:/', $line)));
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
     * What the regexes cannot reach, for the admin to look at: mentions of the old domain, which
     * can be correct (an email address), and base64 values, which no replace can change.
     *
     * @return list<string>
     */
    private function remaining(WpCli $wp, SearchReplacePlan $plan, Runner $runner): array
    {
        $report = [];
        $mentions = self::countMentions($runner->mustRun($wp->dbSearch($plan->oldHost())));
        if ($mentions > 0) {
            $report[] = "Warning: {$mentions} values still mention {$plan->oldHost()}, such as email addresses. Check them.";
        }
        foreach ($plan->base64Needles() as $needle) {
            if (self::countMentions($runner->mustRun($wp->dbSearch($needle))) > 0) {
                $report[] = "Warning: {$plan->oldHost()} seems to be inside a base64 value, which cannot be replaced. Check it.";
                break;
            }
        }
        return $report;
    }
}
