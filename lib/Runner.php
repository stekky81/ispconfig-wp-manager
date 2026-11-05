<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Executes commands, or only logs them in dry-run mode.
 */
final class Runner
{
    /** @var list<string> */
    private array $log = [];

    /** @param list<string> $secrets values masked in the log */
    public function __construct(
        private readonly bool $dryRun,
        private readonly array $secrets = [],
        private readonly int $timeout = 600,
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
        $proc = proc_open($command->argv(), $spec, $pipes, $command->cwd, ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
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
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
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
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return ['exit' => (int)$status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** Like run(), but throws on a non-zero exit code. */
    public function mustRun(Command $command): string
    {
        $result = $this->run($command);
        if ($result['exit'] !== 0) {
            $msg = trim($result['stderr'] ?: $result['stdout']);
            foreach ($this->secrets as $s) {
                if ($s !== '') {
                    $msg = str_replace($s, '********', $msg);
                }
            }
            throw new \RuntimeException("Command failed ({$result['exit']}): {$msg}");
        }
        return $result['stdout'];
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }
}
