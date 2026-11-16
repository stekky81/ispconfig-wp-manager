<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Notifications;
use WpManager\ValidationException;

final class NotificationsTest extends TestCase
{
    private const SITE = ['domain' => 'shop.example.com', 'site_url' => 'https://shop.example.com', 'clone_of' => 0];

    public function testTheSettingWinsOverTheIspconfigAdmin(): void
    {
        self::assertSame(['ops@example.com', 'me@example.com'], Notifications::recipients('ops@example.com, me@example.com', 'admin@example.com'));
        self::assertSame(['admin@example.com'], Notifications::recipients('', 'admin@example.com'));
        self::assertSame([], Notifications::recipients('', ''));
    }

    public function testAnInvalidAdminAddressIsRefusedToo(): void
    {
        $this->expectException(ValidationException::class);
        Notifications::recipients('', "admin@example.com\nBcc: x@evil.example");
    }

    // The choice of the maintainer: passwords are seen once, in the panel, never in an email.
    public function testTheReadyNoticeHasNoPassword(): void
    {
        $mail = Notifications::installed(self::SITE + ['secret_wp' => 'enc:SECRET', 'wp_admin_password' => 'Pw123'], 'installed', '');

        self::assertSame('[WordPress Manager] shop.example.com is ready', $mail['subject']);
        self::assertStringContainsString('https://shop.example.com', $mail['body']);
        self::assertStringNotContainsString('SECRET', $mail['body']);
        self::assertStringNotContainsString('Pw123', $mail['body']);
        self::assertStringContainsString('once only', $mail['body']);
    }

    public function testWarningsAndErrorsOfAnInstallAreSaid(): void
    {
        $warning = Notifications::installed(self::SITE, 'warning', 'Warning: Installation of the plugin x failed');
        self::assertSame('[WordPress Manager] shop.example.com is ready, with warnings', $warning['subject']);
        self::assertStringContainsString('plugin x failed', $warning['body']);

        $error = Notifications::installed(['clone_of' => 4] + self::SITE, 'error', 'Copy of the files failed');
        self::assertSame('[WordPress Manager] The new clone shop.example.com was not installed', $error['subject']);
        self::assertStringContainsString('Copy of the files failed', $error['body']);
    }

    public function testRollbacksAndFailuresCannotWait(): void
    {
        foreach (['error', 'failed', 'rolled_back', 'rollback_failed'] as $outcome) {
            self::assertTrue(Notifications::isUrgent($outcome), $outcome);
        }
        foreach (['done', 'updated', 'installed', 'warning', ''] as $outcome) {
            self::assertFalse(Notifications::isUrgent($outcome), $outcome);
        }
    }

    public function testAnAlertSaysWhatHappened(): void
    {
        $mail = Notifications::alert(self::SITE, 'auto_update', 'rolled_back', 'Broken after the update: home (HTTP 500).');
        self::assertSame('[WordPress Manager] Automatic update of shop.example.com was rolled back', $mail['subject']);
        self::assertStringContainsString('home (HTTP 500)', $mail['body']);

        $urgent = Notifications::alert(self::SITE, 'push', 'rollback_failed', 'x');
        self::assertStringStartsWith('[WordPress Manager] URGENT: Push of the staging to production of shop.example.com', $urgent['subject']);
    }

    // Text of a site reaches the email: no control characters, no endless output.
    public function testTextOfASiteIsCleaned(): void
    {
        $mail = Notifications::alert(self::SITE, 'update', 'failed', "bad\x07\x1b[31m text " . str_repeat('x', 5000) . "\xff");
        self::assertStringNotContainsString("\x07", $mail['body']);
        self::assertStringNotContainsString("\x1b", $mail['body']);
        self::assertLessThan(3300, mb_strlen($mail['body']));
        self::assertTrue(mb_check_encoding($mail['body'], 'UTF-8'));
    }

    public function testASummaryIsDueOncePerDayOrWeekFromSixInTheMorning(): void
    {
        $monday5 = (int)strtotime('2026-11-16 05:59:00');
        $monday6 = (int)strtotime('2026-11-16 06:00:00');
        $wednesday3 = (int)strtotime('2026-11-18 03:00:00');

        self::assertSame('', Notifications::summaryKey('daily', $monday5));
        self::assertSame('summary-2026-11-16', Notifications::summaryKey('daily', $monday6));
        self::assertSame('', Notifications::summaryKey('weekly', $monday5));
        self::assertSame('summary-2026-W47', Notifications::summaryKey('weekly', $monday6));
        // A node that was down on Monday still sends the summary of the week.
        self::assertSame('summary-2026-W47', Notifications::summaryKey('weekly', $wednesday3));
        self::assertSame('', Notifications::summaryKey('off', $monday6));
    }

    public function testNothingToReportSendsNothing(): void
    {
        $sites = [['domain' => 'a.example.com', 'status' => 'installed', 'action_status' => 'done', 'updates_count' => 0]];
        self::assertNull(Notifications::summary('weekly', $sites, []));
    }

    public function testTheSummaryListsProblemsEventsAndUpdates(): void
    {
        $sites = [
            ['domain' => 'a.example.com', 'status' => 'installed', 'action_status' => 'rolled_back', 'action_message' => 'x', 'updates_count' => 2],
            ['domain' => 'b.example.com', 'status' => 'error', 'status_message' => 'Download of WordPress failed', 'updates_count' => 0],
            ['domain' => 'c.example.com', 'status' => 'installed', 'action_status' => 'updated', 'updates_count' => 0],
            ['domain' => 'd.example.com', 'status' => 'installed', 'action_status' => 'rollback_failed', 'action_message' => "Still\nbroken", 'updates_count' => 0],
        ];
        $events = [
            ['domain' => 'a.example.com', 'kind' => 'auto_update', 'outcome' => 'failed', 'message' => 'first', 'created' => '2026-11-14 03:10:00'],
            ['domain' => 'c.example.com', 'kind' => 'auto_update', 'outcome' => 'updated', 'message' => '', 'created' => '2026-11-15 03:10:22'],
            ['domain' => 'a.example.com', 'kind' => 'auto_update', 'outcome' => 'rolled_back', 'message' => "Broken after\nthe update", 'created' => '2026-11-15 04:00:00'],
        ];
        $mail = Notifications::summary('daily', $sites, $events);

        self::assertNotNull($mail);
        self::assertSame('[WordPress Manager] Daily summary: 3 to look at, 1 with updates', $mail['subject']);
        // The latest problem of the period, on one line.
        self::assertStringContainsString('a.example.com: automatic update, rolled back. Broken after the update', $mail['body']);
        self::assertStringNotContainsString('first', $mail['body']);
        self::assertStringContainsString('b.example.com: not installed. Download of WordPress failed', $mail['body']);
        self::assertStringContainsString('d.example.com: broken, the rollback did not repair it. Still broken', $mail['body']);
        self::assertStringContainsString('2026-11-15 03:10 c.example.com: automatic update, updated', $mail['body']);
        self::assertStringContainsString('a.example.com: 2', $mail['body']);
        self::assertStringNotContainsString('c.example.com: 0', $mail['body']);
    }

    // Decided with the maintainer: an old warning stays on the page of its site, not in every summary.
    public function testAnOldWarningIsNotRepeated(): void
    {
        $sites = [
            ['domain' => 'a.example.com', 'status' => 'installed', 'action_status' => 'warning', 'action_message' => 'old', 'updates_count' => 0],
            ['domain' => 'b.example.com', 'status' => 'installed', 'action_status' => 'rolled_back', 'action_message' => 'old', 'updates_count' => 0],
        ];
        self::assertNull(Notifications::summary('weekly', $sites, []));
    }

    public function testANewVulnerabilityTellsHowItEndsAndCarriesTheCopyright(): void
    {
        $vulns = [
            ['id' => 'a', 'type' => 'plugin', 'slug' => 'wpvivid-backuprestore', 'version' => '0.9.100', 'title' => 'Path Traversal', 'cvss_score' => 7.2, 'cvss_rating' => 'High', 'link' => 'https://www.wordfence.com/threat-intel/vulnerabilities/id/a', 'fixed_in' => '0.9.138'],
            ['id' => 'b', 'type' => 'theme', 'slug' => 'old-theme', 'version' => '1.0', 'title' => 'XSS', 'cvss_score' => null, 'cvss_rating' => '', 'link' => 'https://www.wordfence.com/threat-intel/vulnerabilities/id/b', 'fixed_in' => ''],
        ];
        $mail = Notifications::vulnerabilities(['domain' => 'a.test', 'site_url' => 'https://a.test'], $vulns, "Copyright 2012-2026 Defiant Inc. License text.", true);

        self::assertStringContainsString('a.test: 2', $mail['subject']);
        self::assertStringContainsString('plugin wpvivid-backuprestore 0.9.100 [High 7.2]: Path Traversal', $mail['body']);
        self::assertStringContainsString('fixed in 0.9.138', $mail['body']);
        self::assertStringContainsString('no fixed version yet', $mail['body']);
        self::assertStringContainsString('https://www.wordfence.com/threat-intel/vulnerabilities/id/a', $mail['body']);
        self::assertStringContainsString('installed at the next run', $mail['body']);
        self::assertStringContainsString('Copyright 2012-2026 Defiant Inc. License text.', $mail['body']);
    }

    public function testTheSummaryCountsVulnerableSites(): void
    {
        $mail = Notifications::summary('weekly', [['domain' => 'a.test', 'status' => 'installed', 'updates_count' => 0, 'vulns_count' => 2, 'vulns_fixable' => 1]], []);

        self::assertNotNull($mail);
        self::assertStringContainsString('1 vulnerable', $mail['subject']);
        self::assertStringContainsString('a.test: 2, 1 with a fixed version', $mail['body']);
    }
}
