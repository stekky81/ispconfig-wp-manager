<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\AutoUpdatePolicy;

final class AutoUpdatePolicyTest extends TestCase
{
    private function report(?string $core = null, array $plugins = [], array $themes = []): array
    {
        $item = static fn(string $name, string $update): array => ['name' => $name, 'status' => 'active', 'version' => '1', 'update' => $update];
        return [
            'version' => '7.1.3',
            'core_update' => $core,
            'plugins' => array_map($item, array_keys($plugins), array_values($plugins)),
            'themes' => array_map($item, array_keys($themes), array_values($themes)),
        ];
    }

    /** @dataProvider coreVersions */
    public function testOnlyMinorCoreReleasesAreAutomatic(string $from, string $to, bool $minor): void
    {
        self::assertSame($minor, AutoUpdatePolicy::isMinorCoreUpdate($from, $to));
    }

    public static function coreVersions(): array
    {
        return [
            'security release' => ['7.1.3', '7.1.4', true],
            'first minor' => ['7.1', '7.1.1', true],
            'next branch' => ['7.1.4', '7.2', false],
            'next major' => ['7.1.4', '8.0', false],
            'older' => ['7.1.4', '7.1.3', false],
            'unknown' => ['', '7.1.4', false],
        ];
    }

    public function testPluginsAndThemesAreAutomatic(): void
    {
        self::assertTrue(AutoUpdatePolicy::hasAutomaticUpdates($this->report(null, ['a' => '2'])));
        self::assertTrue(AutoUpdatePolicy::hasAutomaticUpdates($this->report(null, [], ['t' => '2'])));
    }

    public function testAMinorCoreReleaseIsAutomatic(): void
    {
        self::assertTrue(AutoUpdatePolicy::hasAutomaticUpdates($this->report('7.1.4')));
    }

    // Regression: only the newest version was on record, so a new branch hid a security release.
    public function testAMinorReleaseCountsEvenWithANewBranchAvailable(): void
    {
        $report = $this->report('7.2') + ['core_minor' => '7.1.4'];

        self::assertTrue(AutoUpdatePolicy::hasAutomaticUpdates($report));
    }

    public function testNewBranchIsReportedForTheButton(): void
    {
        self::assertSame('7.2', AutoUpdatePolicy::majorUpdate($this->report('7.2') + ['core_minor' => '7.1.4']));
        self::assertSame('', AutoUpdatePolicy::majorUpdate($this->report('7.1.4')));
        self::assertSame('', AutoUpdatePolicy::majorUpdate($this->report()));
    }

    public function testAMajorCoreReleaseAloneStartsNothing(): void
    {
        self::assertFalse(AutoUpdatePolicy::hasAutomaticUpdates($this->report('7.2')));
    }

    public function testNothingToDo(): void
    {
        self::assertFalse(AutoUpdatePolicy::hasAutomaticUpdates($this->report(null, ['a' => ''])));
    }

    // Regression guard: without this a broken update would break the site again every day.
    public function testTheSameUpdatesAreNotRetriedAfterARollback(): void
    {
        $report = $this->report(null, ['a' => '2']);
        $site = ['auto_update' => 'y', 'auto_skip' => AutoUpdatePolicy::fingerprint($report)];

        self::assertFalse(AutoUpdatePolicy::shouldRun($site, $report, true));
    }

    public function testANewVersionIsTriedAgain(): void
    {
        $site = ['auto_update' => 'y', 'auto_skip' => AutoUpdatePolicy::fingerprint($this->report(null, ['a' => '2']))];

        self::assertTrue(AutoUpdatePolicy::shouldRun($site, $this->report(null, ['a' => '3']), true));
    }

    public function testAMajorCoreReleaseDoesNotChangeTheFingerprint(): void
    {
        self::assertSame(
            AutoUpdatePolicy::fingerprint($this->report(null, ['a' => '2'])),
            AutoUpdatePolicy::fingerprint($this->report('7.2', ['a' => '2'])),
        );
    }

    public function testBothSwitchesMustBeOn(): void
    {
        $report = $this->report(null, ['a' => '2']);

        self::assertTrue(AutoUpdatePolicy::shouldRun(['auto_update' => 'y', 'auto_skip' => ''], $report, true));
        self::assertFalse(AutoUpdatePolicy::shouldRun(['auto_update' => 'n', 'auto_skip' => ''], $report, true));
        self::assertFalse(AutoUpdatePolicy::shouldRun(['auto_update' => 'y', 'auto_skip' => ''], $report, false));
    }
}
