<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests;

use PHPUnit\Framework\TestCase;
use WPStation\Config;
use WPStation\NativeUpdates;
use WPStation\PathGuard;
use WPStation\Runner;

final class NativeUpdatesTest extends TestCase
{
    /** @dataProvider modes */
    public function testTheModeComesFromTheTwoSwitches(string $mode, string $autoUpdate, string $autoCore): void
    {
        self::assertSame(['auto_update' => $autoUpdate, 'auto_core' => $autoCore], NativeUpdates::switches($mode));
        self::assertSame($mode, NativeUpdates::mode(NativeUpdates::switches($mode)));
    }

    public static function modes(): array
    {
        return [
            'wordpress' => ['wordpress', 'n', 'n'],
            'plugins' => ['plugins', 'y', 'n'],
            'all' => ['all', 'y', 'y'],
        ];
    }

    public function testARecordWithoutTheCoreSwitchLeavesTheCoreToWordPress(): void
    {
        self::assertSame('plugins', NativeUpdates::mode(['auto_update' => 'y']));
        self::assertSame('wordpress', NativeUpdates::mode(['auto_update' => 'n', 'auto_core' => 'y']));
        self::assertSame('wordpress', NativeUpdates::mode([]));
    }

    public function testAnUnknownModeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NativeUpdates::switches('everything');
    }

    /** With the automatic updates of the module off in the settings, WordPress gets them back on every site. */
    public function testWhatTheSiteMustHave(): void
    {
        self::assertSame('', NativeUpdates::desired(NativeUpdates::switches('wordpress'), true));
        self::assertSame('plugins', NativeUpdates::desired(NativeUpdates::switches('plugins'), true));
        self::assertSame('all', NativeUpdates::desired(NativeUpdates::switches('all'), true));
        self::assertSame('', NativeUpdates::desired(NativeUpdates::switches('all'), false));
        self::assertSame('', NativeUpdates::desired(NativeUpdates::switches('plugins'), false));
    }

    /** @dataProvider states */
    public function testTheMustUsePluginIsValidPhpAndMarkedAsOurs(string $state, bool $core): void
    {
        $contents = NativeUpdates::fileContents($state);
        $file = tempnam(sys_get_temp_dir(), 'wpstation-mu');
        file_put_contents($file, $contents);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
        unlink($file);

        self::assertSame(0, $exit, implode("\n", $output));
        self::assertStringStartsWith('<?php', $contents);
        self::assertStringContainsString(NativeUpdates::MARKER, $contents);
        self::assertStringContainsString('Plugin Name: WPStation updates', $contents);
        self::assertStringContainsString("add_filter('auto_update_plugin', '__return_false', PHP_INT_MAX);", $contents);
        self::assertStringContainsString("add_filter('auto_update_theme', '__return_false', PHP_INT_MAX);", $contents);
        self::assertSame($core, str_contains($contents, "'auto_update_core'"));
        // Translations stay with WordPress in every mode.
        self::assertStringNotContainsString('auto_update_translation', $contents);
    }

    public static function states(): array
    {
        return ['plugins' => ['plugins', false], 'all' => ['all', true]];
    }

    public function testNoFileForTheModeWordPress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NativeUpdates::fileContents('');
    }

    /** @dataProvider coreConfigurations */
    public function testWhatWordPressDoesWithItsCoreReleases(array $disabled, array $core, string $state): void
    {
        self::assertSame($state, NativeUpdates::coreState($disabled, $core));
    }

    public static function coreConfigurations(): array
    {
        $missing = ['exit' => 1, 'stdout' => ''];
        $value = static fn(string $out): array => ['exit' => 0, 'stdout' => $out . "\n"];
        return [
            'nothing set: minor releases' => [$missing, $missing, 'minor'],
            'updater disabled' => [$value('1'), $missing, 'off'],
            'updater disabled = false' => [$value(''), $missing, 'minor'],
            'core false (printed empty)' => [$missing, $value(''), 'off'],
            'core minor' => [$missing, $value('minor'), 'minor'],
            'core true (printed 1)' => [$missing, $value('1'), 'all'],
            'core beta' => [$missing, $value('beta'), 'all'],
        ];
    }

    public function testThePluginsAndThemesWithTheirOwnAutomaticUpdates(): void
    {
        $items = [
            ['name' => 'akismet', 'auto_update' => 'on'],
            ['name' => 'hello', 'auto_update' => 'off'],
            ['name' => ['x'], 'auto_update' => 'on'],
            'junk',
        ];
        self::assertSame(['akismet'], NativeUpdates::turnedOn($items));
    }

    public function testThePageSaysWhoUpdatesWhat(): void
    {
        $report = ['native_core' => 'minor', 'native_plugins' => ['akismet'], 'native_themes' => []];
        $keys = static fn(array $notes): array => array_column($notes, 0);

        self::assertSame(['native_core_minor_txt', 'native_extensions_on_txt'], $keys(NativeUpdates::notes('wordpress', true, $report)));
        self::assertSame('akismet', NativeUpdates::notes('wordpress', true, $report)[1][1]);
        self::assertSame(['native_core_minor_txt', 'native_extensions_ours_txt'], $keys(NativeUpdates::notes('plugins', true, $report)));
        self::assertSame(['native_core_ours_txt', 'native_extensions_ours_txt'], $keys(NativeUpdates::notes('all', true, $report)));
        // The switch in the settings is off: WordPress has it all back.
        self::assertSame(['native_global_off_txt', 'native_core_minor_txt', 'native_extensions_on_txt'], $keys(NativeUpdates::notes('all', false, $report)));
    }

    public function testNobodyInstallsTheMinorReleasesIsSaid(): void
    {
        $notes = array_column(NativeUpdates::notes('plugins', true, ['native_core' => 'off']), 0);

        self::assertSame(['native_core_off_txt', 'native_core_nobody_txt', 'native_extensions_ours_txt'], $notes);
    }

    public function testAnOldCheckSaysOnlyWhatItKnows(): void
    {
        self::assertSame([], NativeUpdates::notes('wordpress', true, []));
        self::assertSame([['native_extensions_none_txt', '']], NativeUpdates::notes('wordpress', true, ['native_plugins' => [], 'native_themes' => []]));
    }

    public function testADryRunWritesNothing(): void
    {
        $runner = new Runner(true);
        $updates = new NativeUpdates(Config::fromArray([]), new PathGuard(sys_get_temp_dir()));

        self::assertSame('', $updates->apply(['document_root' => '/nowhere', 'system_user' => 'web1'], 'all', $runner));
        self::assertSame([], $runner->log());
    }

    public function testAnUnknownStateIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new NativeUpdates(Config::fromArray([]), new PathGuard(sys_get_temp_dir())))->apply([], 'some', new Runner(true));
    }
}
