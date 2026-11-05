<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * File-based job spool on each node: one JSON file per job, written atomically.
 * The server plugin pushes, the cron worker pops. Only root can read the folder.
 */
final class JobQueue
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create spool dir {$dir}");
        }
    }

    public function push(string $type, int $siteId, array $data): string
    {
        if (!preg_match('/^[a-z_]+$/', $type)) {
            throw new \InvalidArgumentException("Invalid job type: {$type}");
        }
        $name = sprintf('%s-%d.json', $type, $siteId);
        $tmp = $this->dir . '/.' . $name . '.' . bin2hex(random_bytes(4));
        $json = json_encode([
            'type' => $type,
            'site_id' => $siteId,
            'created' => time(),
            'attempts' => 0,
            'data' => $data,
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write job {$name}");
        }
        chmod($tmp, 0o600);
        rename($tmp, $this->dir . '/' . $name);
        return $name;
    }

    /** @return list<string> job file names, oldest first */
    public function pending(): array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b) ?: strcmp($a, $b));
        return array_map('basename', $files);
    }

    public function read(string $name): array
    {
        $path = $this->path($name);
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Corrupted job {$name}");
        }
        return $data;
    }

    public function update(string $name, array $job): void
    {
        $path = $this->path($name);
        file_put_contents($path . '.tmp', json_encode($job, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($path . '.tmp', 0o600);
        rename($path . '.tmp', $path);
    }

    public function remove(string $name): void
    {
        @unlink($this->path($name));
    }

    private function path(string $name): string
    {
        if (!preg_match('/^[a-z_]+-[0-9]+\.json$/', $name)) {
            throw new \InvalidArgumentException("Invalid job name: {$name}");
        }
        return $this->dir . '/' . $name;
    }
}
