<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests\Integration;

use WPStation\NativeUpdates;
use WPStation\Runner;

/**
 * The must-use plugin that hands the automatic updates of a site from WordPress to WPStation, in a real WordPress.
 */
final class NativeUpdatesSiteTest extends IntegrationTestCase
{
    private static function file(): string
    {
        return self::web(901) . '/' . NativeUpdates::FILE;
    }

    /** What WordPress decides, with every must-use plugin loaded. */
    private static function decides(string $filter): string
    {
        $code = 'echo var_export(apply_filters(' . var_export($filter, true) . ', true, null), true);';
        return trim(self::as(901, [(string)getenv('WPSTATION_IT_WP_CLI'), 'eval', $code, '--path=' . self::web(901)])['stdout']);
    }

    private static function native(): NativeUpdates
    {
        return new NativeUpdates(self::config(), self::guard());
    }

    protected function tearDown(): void
    {
        self::as(901, ['rm', '-f', '--', self::file()]);
        parent::tearDown();
    }

    public function testEachModeHandsOverWhatItSays(): void
    {
        self::assertSame('', self::native()->apply(self::site(901), 'plugins', new Runner(false)));
        clearstatcache();
        self::assertFileExists(self::file());
        self::assertSame('web901', posix_getpwuid((int)fileowner(self::file()))['name']);
        self::assertSame('false', self::decides('auto_update_plugin'));
        self::assertSame('false', self::decides('auto_update_theme'));
        self::assertSame('true', self::decides('auto_update_core'), 'WordPress keeps its minor releases');

        self::assertSame('', self::native()->apply(self::site(901), 'all', new Runner(false)));
        self::assertSame('false', self::decides('auto_update_core'));

        self::assertSame('', self::native()->apply(self::site(901), '', new Runner(false)));
        clearstatcache();
        self::assertFileDoesNotExist(self::file());
        self::assertSame('true', self::decides('auto_update_plugin'));
    }

    public function testAFileOfSomeoneElseIsLeftAlone(): void
    {
        self::as(901, ['mkdir', '-p', '--', dirname(self::file())]);
        self::as(901, ['tee', '--', self::file()], "<?php\n// mine\n");

        self::assertStringContainsString('did not write', self::native()->apply(self::site(901), 'plugins', new Runner(false)));
        self::assertStringContainsString('did not write', self::native()->apply(self::site(901), '', new Runner(false)));
        clearstatcache();
        self::assertSame("<?php\n// mine\n", file_get_contents(self::file()));
    }

    public function testAMustUseFolderElsewhereIsNotGuessed(): void
    {
        self::wp(901, 'config', 'set', 'WPMU_PLUGIN_DIR', '/nowhere');
        try {
            $message = self::native()->apply(self::site(901), 'plugins', new Runner(false));
        } finally {
            self::wp(901, 'config', 'delete', 'WPMU_PLUGIN_DIR');
        }
        self::assertStringContainsString('WPMU_PLUGIN_DIR', $message);
        clearstatcache();
        self::assertFileDoesNotExist(self::file());
    }
}
