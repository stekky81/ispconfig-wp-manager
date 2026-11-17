<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

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
    public static function sandboxed(string $wpBinary, string $phpBinary, string $siteRoot, string $systemUser, ?string $webRoot = null): self
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
        return new self($wpBinary, $webRoot ?? $siteRoot . '/web', $systemUser, $phpBinary, $arguments);
    }

    /** The sandboxed WP-CLI of a record of wpstation_site, in web/ or in its subfolder. */
    public static function forSite(Config $config, PathGuard $guard, array $site): self
    {
        return self::sandboxed(
            $config->get('wp_cli'),
            $config->get('php_cli'),
            $guard->assertInside((string)$site['document_root']),
            (string)$site['system_user'],
            Subfolder::webRoot($site, $guard),
        );
    }

    /** @param array{exit:int, stdout:string, stderr:string} $result of `wp config get NAME`: false when it is missing */
    public static function isTrue(array $result): bool
    {
        $value = strtolower(trim($result['stdout']));
        return $result['exit'] === 0 && !in_array($value, ['', '0', 'false'], true);
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

    /** @param bool $force over the files of an earlier attempt of the same job */
    public function coreDownload(string $locale, bool $force = false): Command
    {
        if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z]+)?$/', $locale)) {
            throw new ValidationException("Invalid locale: {$locale}");
        }
        return $this->cmd(array_merge(['core', 'download', '--locale=' . $locale], $force ? ['--force'] : []));
    }

    /** @param bool $force over the wp-config.php of an earlier attempt of the same job */
    public function configCreate(string $dbName, string $dbUser, string $dbPassword, string $dbHost, string $dbPrefix = 'wp_', bool $force = false): Command
    {
        Validator::dbName($dbName);
        Validator::dbName($dbUser);
        return $this->cmd(array_merge([
            'config', 'create',
            '--dbname=' . $dbName,
            '--dbuser=' . $dbUser,
            '--dbhost=' . $dbHost,
            '--dbprefix=' . $dbPrefix,
            '--prompt=dbpass',
        ], $force ? ['--force'] : []), $dbPassword . "\n");
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

    /** An administrator, for the one-click login. The password goes through stdin. Prints the new ID. */
    public function userCreateAdmin(string $login, string $email, string $password): Command
    {
        return $this->cmd([
            'user', 'create', self::userRef($login), Validator::email($email),
            '--role=administrator', '--display_name=WPStation', '--porcelain', '--prompt=user_pass',
            '--skip-plugins', '--skip-themes',
        ], $password . "\n");
    }

    /** No email to the user: the panel shows the password. */
    public function userSetPassword(string $user, string $password): Command
    {
        return $this->cmd(['user', 'update', self::userRef($user), '--skip-email', '--prompt=user_pass', '--skip-plugins', '--skip-themes'], $password . "\n");
    }

    public function userSetRole(string $user, string $role): Command
    {
        if (!preg_match('/^[a-z_]+$/', $role)) {
            throw new ValidationException("Invalid role: {$role}");
        }
        return $this->cmd(['user', 'set-role', self::userRef($user), $role, '--skip-plugins', '--skip-themes']);
    }

    /** What the user wrote goes to another user: never deleted with it. */
    public function userDelete(string $user, int $reassignTo): Command
    {
        return $this->cmd(['user', 'delete', self::userRef($user), '--reassign=' . $reassignTo, '--yes', '--skip-plugins', '--skip-themes']);
    }

    public function userList(?string $role = null): Command
    {
        $filter = $role === null ? [] : ['--role=' . $role];
        return $this->cmd(array_merge(['user', 'list'], $filter, ['--fields=ID,user_login,roles', '--format=json', '--skip-plugins', '--skip-themes']));
    }

    public function userGet(string $user): Command
    {
        return $this->cmd(['user', 'get', self::userRef($user), '--field=ID', '--skip-plugins', '--skip-themes']);
    }

    public function userSessionCount(string $user): Command
    {
        return $this->cmd(['user', 'session', 'list', self::userRef($user), '--format=count', '--skip-plugins', '--skip-themes']);
    }

    public function userSessionsDestroy(string $user): Command
    {
        return $this->cmd(['user', 'session', 'destroy', self::userRef($user), '--all', '--skip-plugins', '--skip-themes']);
    }

    /** @param 'plugin'|'theme' $type */
    public function isInstalled(string $type, string $slug): Command
    {
        return $this->cmd([self::extensionType($type), 'is-installed', Validator::wpExtension($slug), '--skip-plugins', '--skip-themes']);
    }

    /** Loads WordPress and the plugin: its activation code runs, as in wp-admin. */
    public function pluginActivate(string $slug): Command
    {
        return $this->cmd(['plugin', 'activate', Validator::wpExtension($slug)]);
    }

    /** @param bool $withoutPlugins when a plugin breaks WordPress itself: its deactivation code does not run */
    public function pluginDeactivate(string $slug, bool $withoutPlugins = false): Command
    {
        return $this->cmd(array_merge(['plugin', 'deactivate', Validator::wpExtension($slug)], $withoutPlugins ? ['--skip-plugins', '--skip-themes'] : []));
    }

    /** The main file of an installed plugin. (`wp plugin delete` cannot run in the sandbox: it starts `rm`.) */
    public function pluginPath(string $slug): Command
    {
        return $this->cmd(['plugin', 'path', Validator::wpExtension($slug), '--skip-plugins', '--skip-themes']);
    }

    public function themeActivate(string $slug): Command
    {
        return $this->cmd(['theme', 'activate', Validator::wpExtension($slug)]);
    }

    /** Without --force: WP-CLI refuses the active theme. */
    public function themeDelete(string $slug): Command
    {
        return $this->cmd(['theme', 'delete', Validator::wpExtension($slug), '--skip-plugins', '--skip-themes']);
    }

    /** A user ID, or a login that cannot be read as an option. */
    private static function userRef(string $user): string
    {
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9._@-]{0,59}$/', $user)) {
            throw new ValidationException("Invalid WordPress user: {$user}");
        }
        return $user;
    }

    private static function extensionType(string $type): string
    {
        if (!in_array($type, ['plugin', 'theme'], true)) {
            throw new ValidationException("Invalid type: {$type}");
        }
        return $type;
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
        return $this->cmd(['plugin', 'list', '--fields=name,status,version,update,update_version,auto_update', '--format=json', '--skip-plugins', '--skip-themes']);
    }

    public function themeList(): Command
    {
        return $this->cmd(['theme', 'list', '--fields=name,status,version,update,update_version,auto_update', '--format=json', '--skip-plugins', '--skip-themes']);
    }
}
