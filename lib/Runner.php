<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Executes commands, or only logs them in dry-run mode.
 */
final class Runner
{
    private const PATH = ['PATH' => '/usr/local/bin:/usr/bin:/bin'];

    /** @var list<string> */
    private array $log = [];

    /** @param list<string> $secrets values masked in the log */
    public function __construct(
        private readonly bool $dryRun,
        private readonly array $secrets = [],
        private readonly int $timeout = 600,
        /** Commands of a site are not trusted: their output must not fill the memory of root. */
        private readonly int $maxOutput = 33554432,
        /** Seconds between SIGTERM and SIGKILL when a command has to be stopped. */
        private readonly int $grace = 5,
    ) {
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function run(Command $command): array
    {
        $line = $command->toSafeString($this->secrets);
        if ($this->dryRun) {
            $this->log[] = '[dry-run] ' . $line;
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        $this->log[] = $line;

        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command->argv(), $spec, $pipes, $command->cwd, self::PATH + $command->env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start: ' . $line);
        }
        if ($command->stdin !== null) {
            fwrite($pipes[0], $command->stdin);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $start = time();
        while (true) {
            // Before the status: a process can write everything and end between two checks.
            $stdout .= (string)stream_get_contents($pipes[1], $this->maxOutput);
            $stderr .= (string)stream_get_contents($pipes[2], $this->maxOutput);
            $this->assertOutputLimit($stdout, $stderr, $proc, $line);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (time() - $start > $this->timeout) {
                $this->stop($proc);
                throw new \RuntimeException('Timeout: ' . $line);
            }
            usleep(100_000);
        }
        $stdout .= (string)stream_get_contents($pipes[1], $this->maxOutput);
        $stderr .= (string)stream_get_contents($pipes[2], $this->maxOutput);
        $this->assertOutputLimit($stdout, $stderr, $proc, $line);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return ['exit' => (int)$status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param resource $proc */
    private function assertOutputLimit(string $stdout, string $stderr, $proc, string $line): void
    {
        if (strlen($stdout) + strlen($stderr) > $this->maxOutput) {
            $this->stop($proc);
            throw new \RuntimeException('Too much output: ' . $line);
        }
    }

    /**
     * SIGTERM first, then SIGKILL for whatever still runs after the grace time: the code of a site can
     * ignore SIGTERM, and `sudo` waits for it while holding what the worker gave it.
     *
     * @param resource ...$procs
     */
    private function stop(...$procs): void
    {
        $running = [];
        foreach ($procs as $proc) {
            if (proc_get_status($proc)['running']) {
                $running[] = $proc;
                proc_terminate($proc);
            }
        }
        $end = microtime(true) + $this->grace;
        while ($running !== [] && microtime(true) < $end) {
            usleep(100_000);
            $running = array_values(array_filter($running, static fn($proc): bool => proc_get_status($proc)['running']));
        }
        if ($running === [] || !function_exists('posix_kill')) {
            return;
        }
        $pids = array_map(static fn($proc): int => (int)proc_get_status($proc)['pid'], $running);
        // Freeze first, so that nothing starts a new process while the tree is read again.
        $seen = [];
        for ($round = 0; $round < 5; $round++) {
            $new = array_diff(self::tree($pids), $seen);
            if ($new === []) {
                break;
            }
            foreach ($new as $pid) {
                if (defined('SIGSTOP')) {
                    posix_kill($pid, \SIGSTOP);
                }
            }
            $seen = array_merge($seen, $new);
        }
        foreach ($seen as $pid) {
            posix_kill($pid, 9);
        }
    }

    /**
     * The processes and everything they started, read from /proc. Without /proc (not Linux) only
     * the processes themselves.
     *
     * @param list<int> $roots
     * @return list<int>
     */
    private static function tree(array $roots): array
    {
        $parents = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
            $stat = @file_get_contents($file);
            if ($stat !== false) {
                $fields = explode(' ', substr($stat, (int)strrpos($stat, ')') + 2));
                $parents[(int)basename(dirname($file))] = (int)($fields[1] ?? 0);
            }
        }
        $tree = $roots;
        for ($i = 0; $i < count($tree); $i++) {
            foreach ($parents as $child => $parent) {
                if ($parent === $tree[$i] && !in_array($child, $tree, true)) {
                    $tree[] = $child;
                }
            }
        }
        return $tree;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Streams the output of one command into another. This is how data moves between two site
     * users without root touching files they control.
     */
    public function pipe(Command $from, Command $to): void
    {
        $line = $from->toSafeString($this->secrets) . ' | ' . $to->toSafeString($this->secrets);
        if ($this->dryRun) {
            $this->log[] = '[dry-run] ' . $line;
            return;
        }
        $this->log[] = $line;

        $fromError = tmpfile();
        $toError = tmpfile();
        $source = proc_open($from->argv(), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $fromError], $sourcePipes, $from->cwd, self::PATH + $from->env);
        if (!is_resource($source)) {
            throw new \RuntimeException('Cannot start: ' . $line);
        }
        $target = proc_open($to->argv(), [0 => $sourcePipes[1], 1 => ['file', '/dev/null', 'w'], 2 => $toError], $targetPipes, $to->cwd, self::PATH + $to->env);
        fclose($sourcePipes[1]);
        if (!is_resource($target)) {
            $this->stop($source);
            throw new \RuntimeException('Cannot start: ' . $line);
        }

        $exits = [];
        $start = time();
        while (count($exits) < 2) {
            foreach (['from' => $source, 'to' => $target] as $name => $process) {
                if (!isset($exits[$name])) {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        $exits[$name] = (int)$status['exitcode'];
                    }
                }
            }
            if (time() - $start > $this->timeout) {
                $this->stop($source, $target);
                throw new \RuntimeException('Timeout: ' . $line);
            }
            if (fstat($fromError)['size'] + fstat($toError)['size'] > $this->maxOutput) {
                $this->stop($source, $target);
                throw new \RuntimeException('Too much output: ' . $line);
            }
            usleep(100_000);
        }
        proc_close($source);
        proc_close($target);

        // The target first: when it stops, the source only reports a broken pipe.
        $code = 0;
        $messages = [];
        foreach (['to' => $toError, 'from' => $fromError] as $name => $errors) {
            if ($exits[$name] !== 0) {
                rewind($errors);
                $code = $code ?: $exits[$name];
                $messages[] = self::failureText((string)stream_get_contents($errors));
            }
        }
        if ($messages !== []) {
            $msg = implode("\n", $messages);
            foreach ($this->secrets as $s) {
                if ($s !== '') {
                    $msg = str_replace($s, '********', $msg);
                }
            }
            throw new \RuntimeException("Command failed ({$code}): {$msg}");
        }
    }

    /** Like run(), but throws on a non-zero exit code. */
    public function mustRun(Command $command): string
    {
        $result = $this->run($command);
        if ($result['exit'] !== 0) {
            $msg = self::failureText($result['stderr'] ?: $result['stdout']);
            foreach ($this->secrets as $s) {
                if ($s !== '') {
                    $msg = str_replace($s, '********', $msg);
                }
            }
            throw new \RuntimeException("Command failed ({$result['exit']}): {$msg}");
        }
        return $result['stdout'];
    }

    /**
     * The output of a failed command with the real error first: warnings come after it, because they
     * can explain it ("Couldn't find 'x' in the WordPress.org plugin directory").
     */
    public static function failureText(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $warning = static fn(string $line): bool => str_starts_with($line, 'Warning: ');
        $rest = array_filter($lines, static fn(string $line): bool => !$warning($line));
        return implode("\n", array_merge($rest, array_filter($lines, $warning)));
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }
}
