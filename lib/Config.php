<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Global configuration: built-in defaults, overridden by /opt/ispconfig-wp-manager/config.php.
 */
final class Config
{
    public const DEFAULT_PATH = '/opt/ispconfig-wp-manager/config.php';

    private const BUILTIN = [
        'dry_run' => true,
        'key_file' => '/etc/ispconfig-wp-manager/secret.key',
        'spool_dir' => '/var/spool/ispconfig-wp-manager',
        'web_root_base' => '/var/www/clients',
        'wp_cli' => '/usr/local/bin/wp',
        'php_cli' => '/usr/bin/php',
        'remote_api' => [
            'url' => 'https://localhost:8080/remote/json.php',
            'user' => '',
            'password' => '',
            'verify_tls' => true,
        ],
        'site_defaults' => [
            'php' => 'php-fpm',
            'server_php_id' => 0,
            'hd_quota' => -1,
            'traffic_quota' => -1,
            'ssl' => 'y',
            'ssl_letsencrypt' => 'y',
            'subdomain' => 'www',
            'database_server_id' => null,
            'db_host' => 'localhost',
            // Same placeholder syntax as System → Interface → Main config → DB prefix
            'db_prefix' => 'c[CLIENT_ID]_',
        ],
        'updates' => [
            // Plugins, themes and minor core releases. Nothing runs while dry_run is on.
            'auto' => 'y',
        ],
        'wordpress' => [
            'locale' => 'it_IT',
            'plugins' => [],
            'theme' => null,
            'constants' => [
                'DISALLOW_FILE_EDIT' => ['true', true],
                'WP_POST_REVISIONS' => ['10', true],
            ],
        ],
    ];

    private function __construct(private readonly array $data)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= self::DEFAULT_PATH;
        $user = [];
        if (is_file($path)) {
            $user = require $path;
            if (!is_array($user)) {
                throw new \RuntimeException("{$path} must return an array");
            }
        }
        return self::fromArray($user);
    }

    /**
     * For root: config.php is PHP code, so it must not be in the hands of a less trusted user.
     * The panel only needs to read it. The default owner is root.
     */
    public static function loadTrusted(?string $path = null, int $owner = 0): self
    {
        $path ??= self::DEFAULT_PATH;
        clearstatcache(true, $path);
        if (is_file($path)) {
            if (fileowner($path) !== $owner) {
                throw new \RuntimeException("{$path} must be owned by user {$owner}: run the installer");
            }
            if ((fileperms($path) & 0o022) !== 0) {
                throw new \RuntimeException("{$path} must not be writable by group or others: run the installer");
            }
        }
        return self::load($path);
    }

    public static function fromArray(array $user): self
    {
        $unknown = array_diff(array_keys($user), array_keys(self::BUILTIN));
        if ($unknown !== []) {
            throw new \RuntimeException('Unknown config keys: ' . implode(', ', $unknown));
        }
        return new self(self::merge(self::BUILTIN, $user));
    }

    /** A copy with these values on top, for example the settings saved in the panel. */
    public function withOverrides(array $overrides): self
    {
        return new self(self::merge($this->data, $overrides));
    }

    /** Recursive merge where the user value replaces lists instead of appending to them. */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v) && !array_is_list($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /** Dot notation: get('remote_api.url') */
    public function get(string $key): mixed
    {
        $value = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                throw new \RuntimeException("Missing config key: {$key}");
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function isDryRun(): bool
    {
        return $this->get('dry_run') !== false;
    }
}
