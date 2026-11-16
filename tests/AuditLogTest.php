<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\AuditLog;
use WpManager\SiteActions;

final class AuditLogTest extends TestCase
{
    public function testARowKeepsWhatWasAskedAndNothingDangerous(): void
    {
        $row = AuditLog::row("admin\n", '192.168.1.7', ['wpm_site_id' => '4', 'domain' => 'a.test'], 'plugin_delete', "evil\x1b[31m", str_repeat('x', 2000));

        self::assertSame('admin ', $row['username']);
        self::assertSame('192.168.1.7', $row['ip']);
        self::assertSame(4, $row['wpm_site_id']);
        self::assertSame('a.test', $row['domain']);
        self::assertSame('evil [31m', $row['param']);
        self::assertSame(1000, mb_strlen($row['detail']));
        self::assertSame('', AuditLog::row('admin', 'not an address', null, 'settings')['ip']);
        self::assertSame(0, AuditLog::row('admin', '::1', null, 'settings')['wpm_site_id']);
    }

    public function testEveryRequestThatTheWorkerRunsFindsItsOutcome(): void
    {
        foreach (SiteActions::SITE_PAGE as $action) {
            self::assertSame($action, AuditLog::eventKind($action), $action);
        }
        self::assertSame('install', AuditLog::eventKind('create'));
        self::assertSame('clone', AuditLog::eventKind('staging'));
        self::assertSame('push', AuditLog::eventKind('push'));
        // Done by the panel alone: no outcome to wait for.
        self::assertSame('', AuditLog::eventKind('settings'));
        self::assertSame('', AuditLog::eventKind('show_password'));
        self::assertSame('', AuditLog::eventKind('remove'));
    }

    public function testFiltersTakeOnlyWhatTheQueryCanUse(): void
    {
        $filters = AuditLog::filters(['site' => ' a.test ', 'user' => ['x'], 'action' => "plugin_delete' OR 1=1", 'from' => '2026-11-01', 'to' => '2026-02-30', 'page' => '3']);

        self::assertSame(['site' => 'a.test', 'user' => '', 'action' => '', 'from' => '2026-11-01', 'to' => '', 'page' => 3], $filters);
        self::assertSame(0, AuditLog::filters(['page' => '-1'])['page']);
    }

    public function testTheWhereHasOnlyPlaceholders(): void
    {
        $filters = AuditLog::filters(['site' => '50%_off', 'user' => 'admin', 'action' => 'backup', 'from' => '2026-11-01', 'to' => '2026-11-02']);

        [$where, $values] = AuditLog::where($filters, 'a');
        self::assertSame('a.domain LIKE ? AND a.username = ? AND a.action = ? AND a.created >= ? AND a.created <= ?', $where);
        self::assertSame(['%50\%\_off%', 'admin', 'backup', '2026-11-01 00:00:00', '2026-11-02 23:59:59'], $values);

        // Automatic events have no user.
        [$where, $values] = AuditLog::where($filters, 'e');
        self::assertStringContainsString('1 = 0', $where);
        self::assertStringContainsString('e.kind = ?', $where);
        self::assertCount(4, $values);

        self::assertSame(['1 = 1', []], AuditLog::where(AuditLog::filters([]), 'a'));
    }
}
