<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Permissions of the files of a site: what anyone can write, what belongs to another user, and a wp-config.php that
 * anyone can read (it holds the database password). The daily check reports them; the fix, asked in the panel, takes
 * away the write bit of others and sets wp-config.php to 0640, as the site user, and puts both back if the site
 * breaks. Files of other users are only reported: changing their owner needs root and may undo something wanted.
 */
final class SitePermissions
{
    public const MAX_LISTED = 20;
    public const CONFIG_MODE = '640';

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
        private readonly HealthCheck $health,
    ) {
    }

    /** @return array{world_writable:int, foreign:int, examples:list<string>, wp_config:string} */
    public function check(array $site, ?Runner $runner = null): array
    {
        $runner ??= new Runner($this->config->isDryRun(), [], 300);
        $web = $this->web($site);
        $user = (string)$site['system_user'];
        // Folders the site user cannot open make find say so on stderr: what it found is still right.
        $found = $runner->run(new Command([
            'find', $web, '-xdev', '!', '-type', 'l',
            '(', '-perm', '-0002', '-printf', "W\t%p\n", '-o', '!', '-user', $user, '-printf', "O\t%u\t%p\n", ')',
        ], $user));
        $mode = $runner->run(new Command(['stat', '-c', '%a', '--', $web . '/wp-config.php'], $user));
        return self::parse($found['stdout'], $web, $mode['exit'] === 0 ? trim($mode['stdout']) : '');
    }

    /** @return array{world_writable:int, foreign:int, examples:list<string>, wp_config:string} */
    public static function parse(string $output, string $web, string $configMode): array
    {
        $report = ['world_writable' => 0, 'foreign' => 0, 'examples' => [], 'wp_config' => preg_match('/^[0-7]{3,4}$/', $configMode) === 1 ? $configMode : ''];
        foreach (preg_split('/\n/', $output) ?: [] as $line) {
            $parts = explode("\t", $line);
            if ($parts[0] === 'W' && count($parts) === 2) {
                $report['world_writable']++;
                $example = 'writable by anyone: ' . self::relative($parts[1], $web);
            } elseif ($parts[0] === 'O' && count($parts) === 3) {
                $report['foreign']++;
                $example = 'owned by ' . mb_substr($parts[1], 0, 32) . ': ' . self::relative($parts[2], $web);
            } else {
                continue;
            }
            if (count($report['examples']) < self::MAX_LISTED) {
                $report['examples'][] = mb_scrub($example, 'UTF-8');
            }
        }
        return $report;
    }

    /** Readable or writable by anyone. */
    public static function configTooOpen(string $mode): bool
    {
        return $mode !== '' && ((int)substr($mode, -1) & 6) !== 0;
    }

    public static function needsFix(array $report): bool
    {
        return (int)($report['world_writable'] ?? 0) > 0 || self::configTooOpen((string)($report['wp_config'] ?? ''));
    }

    /** @return array{outcome:string, message:string} outcome: done, rolled_back or rollback_failed */
    public function fix(array $site, ?Runner $runner = null): array
    {
        $runner ??= new Runner($this->config->isDryRun(), [], 600);
        $web = $this->web($site);
        $user = (string)$site['system_user'];
        $before = $this->health->run($site);

        // Only what the site user owns can change; the list is kept to put it back.
        $listed = $runner->run(new Command(['find', $web, '-xdev', '!', '-type', 'l', '-perm', '-0002', '-user', $user, '-print0'], $user));
        $changed = array_values(array_filter(explode("\0", $listed['stdout']), static fn(string $p): bool => $p !== '' && str_starts_with($p, $web)));
        $mode = trim($runner->run(new Command(['stat', '-c', '%a', '--', $web . '/wp-config.php'], $user))['stdout']);
        try {
            if ($changed !== []) {
                $runner->mustRun(new Command(['xargs', '-0', '-r', 'chmod', 'o-w', '--'], $user, null, implode("\0", $changed)));
            }
            if (self::configTooOpen($mode) || $runner->isDryRun()) {
                $runner->mustRun(new Command(['chmod', self::CONFIG_MODE, '--', $web . '/wp-config.php'], $user));
            }
        } catch (\RuntimeException $e) {
            return $this->undo($site, $changed, $mode, $before, $runner, 'Fixing the permissions failed: ' . $e->getMessage());
        }
        $broken = $this->health->regressionsAfterChange($site, $before);
        if ($broken !== []) {
            return $this->undo($site, $changed, $mode, $before, $runner, 'Broken after the fix: ' . $this->health->describe($broken));
        }
        $config = self::configTooOpen($mode) ? ' wp-config.php set to ' . self::CONFIG_MODE . '.' : '';
        return ['outcome' => 'done', 'message' => count($changed) . ' files and folders no longer writable by anyone.' . $config];
    }

    /** @param list<string> $changed */
    private function undo(array $site, array $changed, string $mode, array $before, Runner $runner, string $reason): array
    {
        $web = $this->web($site);
        $user = (string)$site['system_user'];
        try {
            if ($changed !== []) {
                $runner->mustRun(new Command(['xargs', '-0', '-r', 'chmod', 'o+w', '--'], $user, null, implode("\0", $changed)));
            }
            if (preg_match('/^[0-7]{3,4}$/', $mode) === 1) {
                $runner->mustRun(new Command(['chmod', $mode, '--', $web . '/wp-config.php'], $user));
            }
        } catch (\RuntimeException $e) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. Putting the permissions back failed: " . $e->getMessage()];
        }
        $still = $this->health->regressionsAfterChange($site, $before);
        if ($still !== []) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. Permissions put back, but still broken: " . $this->health->describe($still)];
        }
        return ['outcome' => 'rolled_back', 'message' => "{$reason}. Permissions put back."];
    }

    private static function relative(string $path, string $web): string
    {
        return $path === $web ? '/' : (str_starts_with($path, $web . '/') ? substr($path, strlen($web)) : $path);
    }

    private function web(array $site): string
    {
        return $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web');
    }
}
