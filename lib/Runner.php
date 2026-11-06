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
                proc_terminate($proc);
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
            proc_terminate($proc);
            throw new \RuntimeException('Too much output: ' . $line);
        }
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
            proc_terminate($source);
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
                proc_terminate($source);
                proc_terminate($target);
                throw new \RuntimeException('Timeout: ' . $line);
            }
            if (fstat($fromError)['size'] + fstat($toError)['size'] > $this->maxOutput) {
                proc_terminate($source);
                proc_terminate($target);
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

    /** The output of a failed command without the warnings that come before the real error. */
    public static function failureText(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $rest = array_filter($lines, static fn(string $line): bool => !str_starts_with($line, 'Warning: '));
        return implode("\n", $rest === [] ? $lines : $rest);
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }
}
