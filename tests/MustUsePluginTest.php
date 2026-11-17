<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests;

use PHPUnit\Framework\TestCase;
use WPStation\Config;
use WPStation\MustUsePlugin;
use WPStation\PathGuard;
use WPStation\Runner;

final class MustUsePluginTest extends TestCase
{
    private function plugin(): MustUsePlugin
    {
        return new MustUsePlugin(Config::fromArray([]), new PathGuard(sys_get_temp_dir()));
    }

    public function testOnlyFilesOfTheModuleInTheMustUseFolder(): void
    {
        self::assertSame('wp-content/mu-plugins/wpstation-updates.php', MustUsePlugin::path('wpstation-updates.php'));
        foreach (['../wp-config.php', 'akismet.php', 'wpstation-x.php/../../a.php', 'wpstation-UP.php', ''] as $name) {
            try {
                MustUsePlugin::path($name);
                self::fail("accepted: {$name}");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testContentsWithoutTheMarkerAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->plugin()->put([], 'wpstation-test.php', 'our marker', "<?php\n", new Runner(true));
    }

    public function testADryRunReadsAndWritesNothing(): void
    {
        $runner = new Runner(true);
        $site = ['document_root' => '/nowhere', 'system_user' => 'web1'];

        self::assertSame('', $this->plugin()->put($site, 'wpstation-test.php', 'm', "<?php // m\n", $runner));
        self::assertFalse($this->plugin()->has($site, 'wpstation-test.php', 'm', $runner));
        self::assertSame([], $runner->log());
    }
}
