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

    public function testMustRunReportsTheRealErrorWithoutSecrets(): void
    {
        $runner = new Runner(false, ['S3cret']);
        $this->expectExceptionMessageMatches('/^Command failed \(3\): Error: bad \*{8}$/');
        $runner->mustRun(new Command(['/bin/sh', '-c', 'echo "Warning: noise" >&2; echo "Error: bad S3cret" >&2; exit 3']));
    }
}
