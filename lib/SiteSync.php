<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Copies everything of a site, files and the whole database, over another existing site of the
 * same node: production to its staging, or the staging back to production. See docs/architecture.md
 * (Staging). Each side runs as its own user and data travels through pipes, as in a clone.
 */
final class SiteSync
{
    /** Kept on the target, never copied: its database settings, its WP-CLI config, its state. */
    private const KEPT = ['/wp-config.php', '/wp-cli.yml', '/wp-cli.local.yml', '/.maintenance'];
    /** Not copied and not kept: a page cache of the target would serve the old pages. */
    private const DROPPED = ['/wp-content/cache'];

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
        private readonly SiteBackups $backups,
        private readonly HealthCheck $health,
    ) {
    }

    /**
     * Staging from production. The staging is backed up first and comes back if the copy fails.
     *
     * @return array{outcome:string, message:string} outcome: done or failed
     */
    public function refresh(array $staging, array $production, ?Runner $runner = null): array
    {
        $this->assertPair($staging, $production);
        $runner ??= $this->runner();
        $backup = $this->backups->create($staging, $runner);
        try {
            $count = $this->copy($production, $staging, $runner);
            // Production may be public: its copy must not be.
            $runner->mustRun($this->wp($staging)->optionUpdate('blog_public', '0'));
        } catch (\RuntimeException $e) {
            return $this->restore($staging, $backup, $runner, 'Update from production failed: ' . $e->getMessage());
        }
        return ['outcome' => 'done', 'message' => "Staging updated from production ({$count} values replaced). Backup {$backup} of the staging kept."];
    }

    /**
     * Production from staging: backup, maintenance mode, copy, health check, and the backup again
     * if production works less than before.
     *
     * @return array{outcome:string, message:string} outcome: done, rolled_back or rollback_failed
     */
    public function push(array $staging, array $production, ?Runner $runner = null): array
    {
        $this->assertPair($staging, $production);
        $runner ??= $this->runner();
        $wp = $this->wp($production);
        $blogPublic = self::flag($runner->mustRun($wp->optionGet('blog_public')));

        $before = $this->health->run($production);
        // If this throws nothing was changed.
        $backup = $this->backups->create($production, $runner);

        $maintenance = $this->webRoot($production) . '/.maintenance';
        $user = (string)$production['system_user'];
        try {
            $runner->mustRun(new Command(['tee', '--', $maintenance], $user, null, '<?php $upgrading = ' . time() . ";\n"));
            $count = $this->copy($staging, $production, $runner);
            $runner->mustRun($wp->optionUpdate('blog_public', $blogPublic));
        } catch (\RuntimeException $e) {
            return $this->rollback($production, $backup, $before, $runner, 'Push failed: ' . $e->getMessage());
        } finally {
            $runner->run(new Command(['rm', '-f', '--', $maintenance], $user));
        }

        $broken = $this->health->regressionsAfterChange($production, $before);
        if ($broken !== []) {
            return $this->rollback($production, $backup, $before, $runner, 'Broken after the push: ' . $this->health->describe($broken));
        }
        return ['outcome' => 'done', 'message' => "Staging pushed to production ({$count} values replaced). Backup {$backup} of production kept."];
    }

    /** A staging and its own production site, on one node, with two databases. */
    public static function assertPair(array $staging, array $production): void
    {
        if ((int)($staging['staging_of'] ?? 0) === 0 || (int)$staging['staging_of'] !== (int)$production['wpm_site_id']) {
            throw new \RuntimeException("{$staging['domain']} is not the staging of {$production['domain']}");
        }
        foreach ([$staging, $production] as $site) {
            if (($site['status'] ?? '') !== 'installed') {
                throw new \RuntimeException("{$site['domain']} is not installed");
            }
        }
        if ((int)$staging['server_id'] !== (int)$production['server_id']) {
            throw new \RuntimeException("{$staging['domain']} is on another node");
        }
        if ((string)$staging['document_root'] === (string)$production['document_root']) {
            throw new \RuntimeException('Staging and production share a folder');
        }
    }

    /** Scheme, host and port: what the replace rules put in place of the old address. */
    public static function base(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException("Unusable site address: {$url}");
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function flag(string $output): string
    {
        return trim($output) === '0' ? '0' : '1';
    }

    /** @return int how many values the address replace changed */
    private function copy(array $from, array $to, Runner $runner): int
    {
        $source = $this->wp($from);
        $target = $this->wp($to);
        $fromWeb = $this->webRoot($from);
        $toWeb = $this->webRoot($to);
        $fromUser = (string)$from['system_user'];
        $toUser = (string)$to['system_user'];

        // The tables come with the prefix of the source, and the target keeps its wp-config.php.
        $prefix = SiteCloner::tablePrefix($runner->mustRun($source->configGet('table_prefix')));
        if (!$runner->isDryRun() && $prefix !== SiteCloner::tablePrefix($runner->mustRun($target->configGet('table_prefix')))) {
            throw new \RuntimeException("{$from['domain']} and {$to['domain']} use different table prefixes");
        }
        $oldUrl = SiteCloner::safeUrl(trim($runner->mustRun($source->optionGet('home'))), (string)$from['domain']);
        $home = SiteCloner::safeUrl(trim($runner->mustRun($target->optionGet('home'))), (string)$to['domain']);
        $siteUrl = SiteCloner::safeUrl(trim($runner->mustRun($target->optionGet('siteurl'))), (string)$to['domain']);

        // Unpacked beside the site first: a copy that breaks halfway leaves the site as it was.
        $incoming = $this->guard->assertInside(rtrim((string)$to['document_root'], '/') . '/tmp') . '/wpmanager-sync';
        try {
            $runner->mustRun(new Command(['rm', '-rf', '--', $incoming], $toUser));
            $runner->mustRun(new Command(['mkdir', '-m', '0700', '--', $incoming], $toUser));
            $exclude = array_map(static fn(string $path): string => '--exclude=.' . $path, array_merge(self::KEPT, self::DROPPED));
            $runner->pipe(
                new Command(array_merge(['tar', '-cz'], $exclude, ['-C', $fromWeb, '.']), $fromUser),
                new Command(['tar', '-xz', '-C', $incoming], $toUser),
            );
            // Excluded paths are also safe from --delete.
            $keep = array_map(static fn(string $path): string => '--exclude=' . $path, self::KEPT);
            $runner->mustRun(new Command(array_merge(['rsync', '-a', '--delete'], $keep, ['--', "{$incoming}/", "{$toWeb}/"]), $toUser));
        } finally {
            $runner->run(new Command(['rm', '-rf', '--', $incoming], $toUser));
        }

        (new SiteDatabase($this->config, $this->guard))->copy($from, $to, $runner);

        $plan = new SearchReplacePlan($oldUrl, self::base($home), rtrim((string)$from['document_root'], '/'), rtrim((string)$to['document_root'], '/'));
        $count = 0;
        foreach ($plan->rules() as [$pattern, $replacement]) {
            $count += (int)trim($runner->mustRun($target->searchReplace($pattern, $replacement)));
        }
        // The addresses of the target, exactly as they were.
        $runner->mustRun($target->optionUpdate('home', $home));
        $runner->mustRun($target->optionUpdate('siteurl', $siteUrl));
        return $count;
    }

    /** @return array{outcome:string, message:string} */
    private function restore(array $site, string $backup, Runner $runner, string $reason): array
    {
        try {
            $this->backups->restore($site, $backup, $runner);
        } catch (\RuntimeException $e) {
            return ['outcome' => 'failed', 'message' => "{$reason}. Restore of backup {$backup} failed: " . $e->getMessage()];
        }
        return ['outcome' => 'failed', 'message' => "{$reason}. Backup {$backup} restored."];
    }

    /** @return array{outcome:string, message:string} */
    private function rollback(array $site, string $backup, array $before, Runner $runner, string $reason): array
    {
        try {
            $this->backups->restore($site, $backup, $runner);
        } catch (\RuntimeException $e) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. Restore of backup {$backup} failed: " . $e->getMessage()];
        }
        $still = $this->health->regressionsAfterChange($site, $before);
        if ($still !== []) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. Backup {$backup} restored, but still broken: " . $this->health->describe($still)];
        }
        return ['outcome' => 'rolled_back', 'message' => "{$reason}. Backup {$backup} restored."];
    }

    /** Everything WP-CLI does here runs in the sandbox: the code of a site is not trusted. */
    private function wp(array $site): WpCli
    {
        $siteRoot = $this->guard->assertInside((string)$site['document_root']);
        return WpCli::sandboxed($this->config->get('wp_cli'), $this->config->get('php_cli'), $siteRoot, (string)$site['system_user']);
    }

    private function webRoot(array $site): string
    {
        return $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web');
    }

    private function runner(): Runner
    {
        return new Runner($this->config->isDryRun(), [], 3600);
    }
}
