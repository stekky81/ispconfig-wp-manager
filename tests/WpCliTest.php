<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Command;
use WpManager\Runner;
use WpManager\ValidationException;
use WpManager\WpCli;

final class WpCliTest extends TestCase
{
    private function wp(): WpCli
    {
        return new WpCli('/usr/local/bin/wp', '/var/www/clients/client1/web5/web', 'web5');
    }

    public function testCommandsRunAsSiteUserNeverRoot(): void
    {
        $argv = $this->wp()->coreDownload('it_IT')->argv();
        self::assertSame(['sudo', '-u', 'web5', '-H', '--'], array_slice($argv, 0, 5));
        self::assertContains('--path=/var/www/clients/client1/web5/web', $argv);
    }

    public function testRejectsNonIspconfigUser(): void
    {
        $this->expectException(ValidationException::class);
        new WpCli('/usr/local/bin/wp', '/var/www/x', 'root');
    }

    public function testSecretsGoThroughStdinNotArguments(): void
    {
        $cmd = $this->wp()->configCreate('c1_shop', 'c1_shopu', 'S3cr3t!', 'localhost');
        self::assertStringNotContainsString('S3cr3t!', implode(' ', $cmd->argv()));
        self::assertSame("S3cr3t!\n", $cmd->stdin);
        self::assertContains('--prompt=dbpass', $cmd->argv());

        $cmd = $this->wp()->coreInstall('https://shop.example.com', 'Shop', 'stefano', 'Adm1nPw', 'a@example.com');
        self::assertStringNotContainsString('Adm1nPw', implode(' ', $cmd->argv()));
        self::assertSame("Adm1nPw\n", $cmd->stdin);
    }

    public function testTitleWithShellCharactersIsOneSafeArgument(): void
    {
        $cmd = $this->wp()->coreInstall('https://x.example.com', "Bob's \$(rm -rf /) shop", 'stefano', 'pw', 'a@example.com');
        self::assertContains("--title=Bob's \$(rm -rf /) shop", $cmd->argv());
        self::assertStringContainsString("'--title=Bob'\\''s \$(rm -rf /) shop'", $cmd->toString());
    }

    public function testInvalidSlugsAndConstantsAreRejected(): void
    {
        foreach ([
            fn() => $this->wp()->pluginInstall('../evil'),
            fn() => $this->wp()->pluginInstall('https://evil.example.com/x.zip'),
            fn() => $this->wp()->themeInstall('a b'),
            fn() => $this->wp()->configSet('lower_case', 'x'),
            fn() => $this->wp()->coreDownload('it_IT; id'),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Case {$i} accepted");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDryRunExecutesNothingAndMasksSecrets(): void
    {
        $runner = new Runner(true, ['S3cr3t!']);
        $marker = sys_get_temp_dir() . '/wpm-dry-run-' . bin2hex(random_bytes(4));
        $runner->run(new Command(['touch', $marker]));
        $runner->run(new Command(['echo', 'S3cr3t!']));
        self::assertFileDoesNotExist($marker);
        self::assertStringStartsWith('[dry-run]', $runner->log()[0]);
        self::assertStringNotContainsString('S3cr3t!', implode("\n", $runner->log()));
    }

    public function testRealRunPassesStdinAndReportsFailure(): void
    {
        $runner = new Runner(false, ['hidden']);
        self::assertSame('hello', $runner->mustRun(new Command(['cat'], null, null, 'hello')));
        try {
            $runner->mustRun(new Command(['sh', '-c', 'echo hidden >&2; exit 3']));
            self::fail('Failure not reported');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('(3)', $e->getMessage());
            self::assertStringNotContainsString('hidden', $e->getMessage());
        }
    }
}
