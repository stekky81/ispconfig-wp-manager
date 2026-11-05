<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Backups of a site, taken before it is changed. Runs on the web node, always as the site user.
 *
 * They live in <site>/tmp/wpmanager-backups: the site user can write there, they count towards
 * the quota and ISPConfig leaves them alone. Its own backups exclude ./tmp, its cleanup of tmp
 * only removes sess_* files, and <site>/backup would not do: it is emptied after 3 days.
 */
final class SiteBackup implements SiteBackups
{
    public const KEEP = 3;
    private const FILES = 'files.tar.gz';
    private const DATABASE = 'db.sql.gz';
    private const COMPLETE = 'COMPLETE';

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    public function directory(array $site): string
    {
        return $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/tmp') . '/wpmanager-backups';
    }

    /** @return string the id of the new backup */
    public function create(array $site, ?Runner $runner = null): string
    {
        $runner ??= $this->runner();
        $user = (string)$site['system_user'];
        $web = $this->webRoot($site);
        $id = date('Ymd-His');
        $dir = $this->directory($site) . '/' . $id;
        $wp = new WpCli($this->config->get('wp_cli'), $web, $user);

        $runner->mustRun(new Command(['mkdir', '-p', '-m', '0700', '--', $dir], $user));
        $runner->mustRun($wp->dbExport("{$dir}/db.sql"));
        $runner->mustRun(new Command(['gzip', '--', "{$dir}/db.sql"], $user));
        $runner->mustRun(new Command(['tar', '-czf', "{$dir}/" . self::FILES, '-C', $web, '.'], $user));
        $this->verify($dir, $user, $runner);
        // Last: a backup without this file was interrupted and is never restored.
        $runner->mustRun(new Command(['touch', '--', "{$dir}/" . self::COMPLETE], $user));

        $this->prune($site, $runner);
        return $id;
    }

    /** @return list<array{id:string, size:int}> complete backups, newest first */
    public function list(array $site): array
    {
        $backups = [];
        foreach ($this->entries($site) as $id) {
            $dir = $this->directory($site) . '/' . $id;
            if (is_file("{$dir}/" . self::COMPLETE) && !is_link("{$dir}/" . self::COMPLETE)) {
                $size = (int)@filesize("{$dir}/" . self::FILES) + (int)@filesize("{$dir}/" . self::DATABASE);
                $backups[] = ['id' => $id, 'size' => $size];
            }
        }
        return $backups;
    }

    /** Keeps the newest complete backups; what is older than the oldest of them goes. */
    public function prune(array $site, ?Runner $runner = null): void
    {
        $runner ??= $this->runner();
        $complete = array_column($this->list($site), 'id');
        $keep = array_slice($complete, 0, self::KEEP);
        $oldestKept = end($keep);
        foreach ($this->entries($site) as $id) {
            if (in_array($id, $keep, true) || ($oldestKept !== false && $id > $oldestKept)) {
                continue;
            }
            $runner->mustRun(new Command(['rm', '-rf', '--', $this->directory($site) . '/' . $id], (string)$site['system_user']));
        }
    }

    /** Nothing in the site changes until the backup is verified and unpacked. */
    public function restore(array $site, string $id, ?Runner $runner = null): void
    {
        if (!preg_match('/^\d{8}-\d{6}$/', $id)) {
            throw new \RuntimeException("Invalid backup: {$id}");
        }
        $runner ??= $this->runner();
        $user = (string)$site['system_user'];
        $web = $this->webRoot($site);
        $dir = $this->directory($site) . '/' . $id;
        if (is_link($dir) || !is_file("{$dir}/" . self::COMPLETE)) {
            throw new \RuntimeException("{$id} is not a complete backup");
        }
        $this->verify($dir, $user, $runner);

        $staging = "{$dir}/restore";
        $runner->mustRun(new Command(['rm', '-rf', '--', $staging], $user));
        $runner->mustRun(new Command(['mkdir', '-m', '0700', '--', $staging], $user));
        $runner->mustRun(new Command(['tar', '-xzf', "{$dir}/" . self::FILES, '-C', $staging], $user));
        $runner->mustRun(new Command(['gzip', '-dk', '--', "{$dir}/" . self::DATABASE], $user));

        $runner->mustRun(new Command(['rsync', '-a', '--delete', '--', "{$staging}/", "{$web}/"], $user));
        $wp = new WpCli($this->config->get('wp_cli'), $web, $user);
        $runner->mustRun($wp->dbImport("{$dir}/db.sql"));

        $runner->mustRun(new Command(['rm', '-rf', '--', $staging, "{$dir}/db.sql"], $user));
    }

    private function verify(string $dir, string $user, Runner $runner): void
    {
        $runner->mustRun(new Command(['gzip', '-t', '--', "{$dir}/" . self::DATABASE], $user));
        $runner->mustRun(new Command(['tar', '-tzf', "{$dir}/" . self::FILES], $user));
    }

    /** @return list<string> backup folders, newest first; site owners control this tree, so links are skipped */
    private function entries(array $site): array
    {
        $root = $this->directory($site);
        if (is_link($root) || !is_dir($root)) {
            return [];
        }
        $ids = array_filter(
            scandir($root) ?: [],
            static fn(string $name): bool => preg_match('/^\d{8}-\d{6}$/', $name) === 1
                && !is_link("{$root}/{$name}") && is_dir("{$root}/{$name}"),
        );
        rsort($ids);
        return array_values($ids);
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
