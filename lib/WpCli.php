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
    /** @param list<string> $phpArguments */
    public function __construct(
        private readonly string $wpBinary,
        private readonly string $webRoot,
        private readonly string $systemUser,
        private readonly ?string $phpBinary = null,
        private readonly array $phpArguments = [],
    ) {
        Validator::systemUser($systemUser);
    }

    /**
     * For everything that runs on a site with nobody looking at it. WordPress and must-use
     * plugins load under the PHP CLI, outside the jail and the limits of the site: here they stay
     * inside the folder of the site and cannot start programs. Commands that start other programs,
     * as wp db export does, cannot use this.
     *
     * This limits PHP, it is not a jail. disable_functions alone is not enough: FFI calls libc
     * directly, and putenv(LD_PRELOAD) with anything that runs sendmail loads a library of the site.
     */
    public static function sandboxed(string $wpBinary, string $phpBinary, string $siteRoot, string $systemUser): self
    {
        $options = [
            'open_basedir' => "{$siteRoot}:{$wpBinary}",
            'disable_functions' => 'exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,dl,putenv',
            'ffi.enable' => '0',
            'sendmail_path' => '/bin/true',
            'sys_temp_dir' => "{$siteRoot}/tmp",
            'upload_tmp_dir' => "{$siteRoot}/tmp",
            'memory_limit' => '512M',
            'display_errors' => '0',
            'log_errors' => '0',
        ];
        $arguments = [];
        foreach ($options as $name => $value) {
            array_push($arguments, '-d', "{$name}={$value}");
        }
        return new self($wpBinary, $siteRoot . '/web', $systemUser, $phpBinary, $arguments);
    }

    private function cmd(array $args, ?string $stdin = null): Command
    {
        $base = $this->phpBinary !== null
            ? array_merge([$this->phpBinary], $this->phpArguments, [$this->wpBinary])
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

    public function configCreate(string $dbName, string $dbUser, string $dbPassword, string $dbHost, string $dbPrefix = 'wp_'): Command
    {
        Validator::dbName($dbName);
        Validator::dbName($dbUser);
        return $this->cmd([
            'config', 'create',
            '--dbname=' . $dbName,
            '--dbuser=' . $dbUser,
            '--dbhost=' . $dbHost,
            '--dbprefix=' . $dbPrefix,
            '--prompt=dbpass',
        ], $dbPassword . "\n");
    }

    public function configHas(string $name): Command
    {
        return $this->cmd(['config', 'has', $name, '--type=constant']);
    }

    public function configDelete(string $name): Command
    {
        return $this->cmd(['config', 'delete', $name, '--type=constant']);
    }

    /** All the constants of wp-config.php, the password included: it stays in the output. */
    public function configList(): Command
    {
        return $this->cmd(['config', 'list', '--format=json']);
    }

    public function configGet(string $name): Command
    {
        return $this->cmd(['config', 'get', $name]);
    }

    public function optionGet(string $name): Command
    {
        return $this->cmd(['option', 'get', $name, '--skip-plugins', '--skip-themes']);
    }

    public function optionUpdate(string $name, string $value): Command
    {
        return $this->cmd(['option', 'update', $name, $value, '--skip-plugins', '--skip-themes']);
    }

    /** Regex mode: every value is unserialized, replaced and serialized again. Prints the count. */
    public function searchReplace(string $pattern, string $replacement): Command
    {
        return $this->cmd([
            'search-replace', $pattern, $replacement,
            '--regex', '--regex-delimiter=#', '--regex-flags=i',
            '--all-tables', '--skip-columns=guid', '--format=count',
            '--skip-plugins', '--skip-themes',
        ]);
    }

    /** Read-only: one line for each value holding the text. */
    public function dbSearch(string $text): Command
    {
        // The characters around each match tell a mention from the end of a longer host name.
        return $this->cmd(['db', 'search', $text, '--all-tables', '--one_line', '--before_context=8', '--after_context=8', '--skip-plugins', '--skip-themes']);
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
        $args = ['plugin', 'install', Validator::wpSlug($slug)];
        if ($activate) {
            $args[] = '--activate';
        }
        return $this->cmd($args);
    }

    public function themeInstall(string $slug, bool $activate = true): Command
    {
        $args = ['theme', 'install', Validator::wpSlug($slug)];
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
    public function coreUpdate(bool $minorOnly = false): Command
    {
        return $this->cmd(array_merge(['core', 'update'], $minorOnly ? ['--minor'] : [], ['--skip-plugins', '--skip-themes']));
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
