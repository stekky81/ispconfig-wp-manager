<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Makes sure the worker only ever touches website directories created by ISPConfig.
 */
final class PathGuard
{
    /** Files ISPConfig puts into a fresh web/ folder; anything else means "not empty". */
    public const ISPCONFIG_DEFAULT_ENTRIES = [
        'index.html', 'standard_index.html', 'favicon.ico', 'robots.txt', 'stats', 'error', '.well-known',
    ];

    public function __construct(private readonly string $root = '/var/www/clients')
    {
    }

    /** Returns the canonical path if it exists and lies inside the allowed root. */
    public function assertInside(string $path): string
    {
        $real = realpath($path);
        $root = realpath($this->root);
        if ($real === false || $root === false) {
            throw new \RuntimeException("Path does not exist: {$path}");
        }
        if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Path outside {$this->root}: {$path}");
        }
        return $real;
    }

    /** True when the folder contains only ISPConfig placeholder files. */
    public function isPristineWebRoot(string $webRoot): bool
    {
        $dir = $this->assertInside($webRoot);
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!in_array($entry, self::ISPCONFIG_DEFAULT_ENTRIES, true)) {
                return false;
            }
        }
        return true;
    }
}
