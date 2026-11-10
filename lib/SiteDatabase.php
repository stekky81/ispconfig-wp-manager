<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Dumps and loads the database of a site without WP-CLI, always as the site user.
 *
 * wp db export and import load wp-config.php and cannot run in the sandbox, because they start
 * mysqldump and mysql. So only the settings are read in the sandbox; the tools start from here.
 */
final class SiteDatabase
{
    /** In a dry run no command gives an answer. */
    private const DRY_RUN = ['name' => 'dry_run', 'user' => 'dry_run', 'password' => '', 'host' => 'localhost', 'charset' => ''];

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    public function dump(array $site, string $file, Runner $runner): void
    {
        $db = $this->settings($site, $runner);
        $runner->mustRun(new Command(
            array_merge(
                [self::tool('mysqldump', 'mariadb-dump'), '--no-defaults'],
                self::connection($db),
                ['--single-transaction', '--quick', '--no-tablespaces', '--result-file=' . $file, '--', $db['name']],
            ),
            (string)$site['system_user'],
            null,
            null,
            ['MYSQL_PWD' => $db['password']],
        ));
    }

    /**
     * A dump belongs to the site user. Without --binary-mode the mysql client obeys its own commands
     * in the file, and \! starts a program outside the jail.
     */
    public function load(array $site, string $file, Runner $runner): void
    {
        $user = (string)$site['system_user'];
        $db = $this->settings($site, $runner);
        $runner->pipe(
            new Command(['cat', '--', $file], $user),
            new Command(
                array_merge(
                    [self::tool('mysql', 'mariadb'), '--no-defaults'],
                    self::connection($db),
                    ['--binary-mode', '--database=' . $db['name']],
                ),
                $user,
                null,
                null,
                ['MYSQL_PWD' => $db['password']],
            ),
        );
    }

    /**
     * The whole database of one site into the database of another, each side as its own user, through
     * a pipe: root never holds the data, and the target only receives SQL, never client commands.
     */
    public function copy(array $from, array $to, Runner $runner): void
    {
        $source = $this->settings($from, $runner);
        $target = $this->settings($to, $runner);
        if (!$runner->isDryRun() && $source['name'] === $target['name']) {
            throw new \RuntimeException("Both sites use the database {$target['name']}");
        }
        $runner->pipe(
            new Command(
                array_merge(
                    [self::tool('mysqldump', 'mariadb-dump'), '--no-defaults'],
                    self::connection($source),
                    ['--single-transaction', '--quick', '--no-tablespaces', '--', $source['name']],
                ),
                (string)$from['system_user'],
                null,
                null,
                ['MYSQL_PWD' => $source['password']],
            ),
            new Command(
                array_merge(
                    [self::tool('mysql', 'mariadb'), '--no-defaults'],
                    self::connection($target),
                    ['--binary-mode', '--database=' . $target['name']],
                ),
                (string)$to['system_user'],
                null,
                null,
                ['MYSQL_PWD' => $target['password']],
            ),
        );
    }

    /**
     * Whatever a site writes in wp-config.php reaches these tools, each value as one whole argument.
     *
     * @return array{name:string, user:string, password:string, host:string, charset:string}
     */
    public static function parseSettings(string $json): array
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            throw new \RuntimeException('Cannot read the database settings of wp-config.php');
        }
        $constants = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($row['type'] ?? '') === 'constant' && is_string($row['name'] ?? null) && is_string($row['value'] ?? null)) {
                $constants[$row['name']] = $row['value'];
            }
        }
        $settings = [
            'name' => $constants['DB_NAME'] ?? '',
            'user' => $constants['DB_USER'] ?? '',
            'password' => $constants['DB_PASSWORD'] ?? '',
            'host' => $constants['DB_HOST'] ?? '',
            'charset' => $constants['DB_CHARSET'] ?? '',
        ];
        foreach (['name', 'user', 'host'] as $key) {
            if ($settings[$key] === '' || preg_match('/[\x00-\x1f\x7f]/', $settings[$key])) {
                throw new \RuntimeException("Invalid database setting in wp-config.php: {$key}");
            }
        }
        if (str_contains($settings['password'], "\0") || !preg_match('/^\w*$/', $settings['charset'])) {
            throw new \RuntimeException('Invalid database setting in wp-config.php');
        }
        return $settings;
    }

    /**
     * As WordPress reads DB_HOST: host, host:port or host:/path/to/socket.
     *
     * @return list<string>
     */
    public static function hostArguments(string $host): array
    {
        $parts = explode(':', $host);
        if (count($parts) !== 2) {
            return ['--host=' . $host];
        }
        [$name, $extra] = $parts;
        if (ctype_digit($extra)) {
            return ['--host=' . $name, '--port=' . $extra, '--protocol=tcp'];
        }
        return $extra === '' ? ['--host=' . $name] : ['--host=' . $name, '--socket=' . $extra];
    }

    /** @return array{name:string, user:string, password:string, host:string, charset:string} */
    private function settings(array $site, Runner $runner): array
    {
        $siteRoot = $this->guard->assertInside((string)$site['document_root']);
        $wp = WpCli::sandboxed($this->config->get('wp_cli'), $this->config->get('php_cli'), $siteRoot, (string)$site['system_user']);
        $output = $runner->mustRun($wp->configList());
        return $runner->isDryRun() ? self::DRY_RUN : self::parseSettings($output);
    }

    /** @return list<string> */
    private static function connection(array $db): array
    {
        $charset = $db['charset'] === '' ? [] : ['--default-character-set=' . $db['charset']];
        return array_merge(self::hostArguments($db['host']), ['--user=' . $db['user']], $charset);
    }

    /** Some systems only have the mariadb names. */
    private static function tool(string ...$names): string
    {
        foreach ($names as $name) {
            foreach (['/usr/local/bin', '/usr/bin', '/bin'] as $directory) {
                if (is_executable("{$directory}/{$name}")) {
                    return $name;
                }
            }
        }
        return $names[0];
    }
}
