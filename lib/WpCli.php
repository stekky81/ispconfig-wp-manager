<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Builds WP-CLI commands for one site. It only builds them; Runner executes them.
 * Secrets are passed through stdin (--prompt) so they never appear in the process list.
 */
final class WpCli
{
    public function __construct(
        private readonly string $wpBinary,
        private readonly string $webRoot,
        private readonly string $systemUser,
        private readonly ?string $phpBinary = null,
    ) {
        Validator::systemUser($systemUser);
    }

    private function cmd(array $args, ?string $stdin = null): Command
    {
        $base = $this->phpBinary !== null
            ? [$this->phpBinary, $this->wpBinary]
            : [$this->wpBinary];
        return new Command(
            array_merge($base, $args, ['--path=' . $this->webRoot, '--no-color']),
            $this->systemUser,
            $this->webRoot,
            $stdin,
        );
    }

    public function coreDownload(string $locale): Command
    {
        if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z]+)?$/', $locale)) {
            throw new ValidationException("Invalid locale: {$locale}");
        }
        return $this->cmd(['core', 'download', '--locale=' . $locale]);
    }

    public function configCreate(string $dbName, string $dbUser, string $dbPassword, string $dbHost): Command
    {
        Validator::dbName($dbName);
        Validator::dbName($dbUser);
        return $this->cmd([
            'config', 'create',
            '--dbname=' . $dbName,
            '--dbuser=' . $dbUser,
            '--dbhost=' . $dbHost,
            '--prompt=dbpass',
        ], $dbPassword . "\n");
    }

    public function coreInstall(string $url, string $title, string $adminUser, string $adminPassword, string $adminEmail): Command
    {
        return $this->cmd([
            'core', 'install',
            '--url=' . $url,
            '--title=' . $title,
            '--admin_user=' . Validator::wpUser($adminUser),
            '--admin_email=' . Validator::email($adminEmail),
            '--skip-email',
            '--prompt=admin_password',
        ], $adminPassword . "\n");
    }

    public function configSet(string $name, string $value, bool $raw = false): Command
    {
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
            throw new ValidationException("Invalid constant name: {$name}");
        }
        $args = ['config', 'set', $name, $value, '--type=constant'];
        if ($raw) {
            $args[] = '--raw';
        }
        return $this->cmd($args);
    }

    public function pluginInstall(string $slug, bool $activate = true): Command
    {
        if (!preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
            throw new ValidationException("Invalid plugin slug: {$slug}");
        }
        $args = ['plugin', 'install', $slug];
        if ($activate) {
            $args[] = '--activate';
        }
        return $this->cmd($args);
    }

    public function themeInstall(string $slug, bool $activate = true): Command
    {
        if (!preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
            throw new ValidationException("Invalid theme slug: {$slug}");
        }
        $args = ['theme', 'install', $slug];
        if ($activate) {
            $args[] = '--activate';
        }
        return $this->cmd($args);
    }

    public function coreVersion(): Command
    {
        return $this->cmd(['core', 'version']);
    }

    /** Updates run without normal plugins and themes, which may be what is broken. Must-use plugins still load. */
    public function coreUpdate(): Command
    {
        return $this->cmd(['core', 'update', '--skip-plugins', '--skip-themes']);
    }

    public function coreUpdateDb(): Command
    {
        return $this->cmd(['core', 'update-db', '--skip-plugins', '--skip-themes']);
    }

    public function pluginUpdateAll(): Command
    {
        return $this->cmd(['plugin', 'update', '--all', '--skip-plugins', '--skip-themes']);
    }

    public function themeUpdateAll(): Command
    {
        return $this->cmd(['theme', 'update', '--all', '--skip-plugins', '--skip-themes']);
    }

    public function dbExport(string $file): Command
    {
        return $this->cmd(['db', 'export', $file, '--skip-plugins', '--skip-themes']);
    }

    public function dbImport(string $file): Command
    {
        return $this->cmd(['db', 'import', $file, '--skip-plugins', '--skip-themes']);
    }

    /** Read-only checks of the update overview. Normal plugins and themes do not load; must-use plugins do. */
    public function coreCheckUpdate(): Command
    {
        return $this->cmd(['core', 'check-update', '--format=json', '--skip-plugins', '--skip-themes']);
    }

    public function pluginList(): Command
    {
        return $this->cmd(['plugin', 'list', '--fields=name,status,version,update,update_version', '--format=json', '--skip-plugins', '--skip-themes']);
    }

    public function themeList(): Command
    {
        return $this->cmd(['theme', 'list', '--fields=name,status,version,update,update_version', '--format=json', '--skip-plugins', '--skip-themes']);
    }
}
