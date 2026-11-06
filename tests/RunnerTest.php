<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Command;
use WpManager\Runner;

final class RunnerTest extends TestCase
{
    // Regression: a WP-CLI warning came first and filled the short message in the site list,
    // hiding the real error.
    public function testWarningsAreDroppedWhenThereIsAnError(): void
    {
        $text = "Warning: Failed to create directory '/x/.wp-cli/cache/': mkdir(): Operation not permitted.\nError: Failed to get url";

        self::assertSame('Error: Failed to get url', Runner::failureText($text));
    }

    public function testOnlyWarningsAreKept(): void
    {
        self::assertSame('Warning: something odd', Runner::failureText('Warning: something odd'));
    }

    public function testOtherOutputIsKept(): void
    {
        self::assertSame("sudo: unknown user\nsecond line", Runner::failureText("sudo: unknown user\nsecond line\n"));
    }

    public function testPipeCarriesTheDataBetweenTwoCommands(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpm-pipe');
        $runner = new Runner(false);
        $runner->pipe(
            new Command(['/bin/sh', '-c', 'echo hello']),
            new Command(['/bin/sh', '-c', 'cat > ' . escapeshellarg($file)]),
        );

        self::assertSame("hello\n", file_get_contents($file));
        unlink($file);
    }

    public function testPipeCarriesMoreThanAPipeBuffer(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpm-pipe');
        (new Runner(false))->pipe(
            new Command(['/bin/sh', '-c', 'head -c 3000000 /dev/zero']),
            new Command(['/bin/sh', '-c', 'cat > ' . escapeshellarg($file)]),
        );

        self::assertSame(3000000, filesize($file));
        unlink($file);
    }

    public function testPipeFailsWhenTheFirstCommandFails(): void
    {
        $this->expectExceptionMessageMatches('/Command failed \(4\): Error: source broken/');
        (new Runner(false))->pipe(
            new Command(['/bin/sh', '-c', 'echo "Error: source broken" >&2; exit 4']),
            new Command(['/bin/sh', '-c', 'cat > /dev/null']),
        );
    }

    public function testPipeFailsWhenTheSecondCommandFails(): void
    {
        $this->expectExceptionMessageMatches('/Command failed \(5\): target broken/');
        (new Runner(false))->pipe(
            new Command(['/bin/sh', '-c', 'echo data']),
            new Command(['/bin/sh', '-c', 'cat > /dev/null; echo "target broken" >&2; exit 5']),
        );
    }

    // Regression: a site could fill the memory of the root worker with endless output.
    public function testEndlessOutputIsStopped(): void
    {
        $this->expectExceptionMessage('Too much output');
        (new Runner(false, [], 10, 100000))->run(new Command(['/bin/sh', '-c', 'head -c 3000000 /dev/zero']));
    }

    public function testEndlessErrorsInAPipeAreStopped(): void
    {
        $this->expectExceptionMessage('Too much output');
        (new Runner(false, [], 10, 100000))->pipe(
            new Command(['/bin/sh', '-c', 'head -c 3000000 /dev/zero >&2; sleep 5']),
            new Command(['/bin/sh', '-c', 'cat > /dev/null']),
        );
    }

    // Regression: a restore that mysql refused only reported "cat: write error: Broken pipe".
    public function testPipeNamesTheTargetBeforeTheBrokenPipeOfTheSource(): void
    {
        $this->expectExceptionMessageMatches('/Command failed \(6\): target refused\nbroken pipe/');
        (new Runner(false))->pipe(
            new Command(['/bin/sh', '-c', 'sleep 1; echo "broken pipe" >&2; exit 1']),
            new Command(['/bin/sh', '-c', 'echo "target refused" >&2; exit 6']),
        );
    }

    public function testPipeDoesNothingInDryRun(): void
    {
        $runner = new Runner(true);
        $runner->pipe(new Command(['false']), new Command(['false']));

        self::assertStringContainsString("[dry-run] 'false' | 'false'", implode("\n", $runner->log()));
    }

    public function testMustRunReportsTheRealErrorWithoutSecrets(): void
    {
        $runner = new Runner(false, ['S3cret']);
        $this->expectExceptionMessageMatches('/^Command failed \(3\): Error: bad \*{8}$/');
        $runner->mustRun(new Command(['/bin/sh', '-c', 'echo "Warning: noise" >&2; echo "Error: bad S3cret" >&2; exit 3']));
    }
}
