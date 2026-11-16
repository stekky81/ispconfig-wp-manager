<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\ClientLimits;

final class ClientLimitsTest extends TestCase
{
    private const OPEN = [
        'limit_web_domain' => -1, 'limit_database' => -1, 'limit_database_user' => -1, 'limit_ftp_user' => -1,
        'limit_shell_user' => -1, 'limit_web_quota' => -1, 'ssh_chroot' => 'no,jailkit,ssh-chroot',
    ];

    public function testNoLimitsNoProblems(): void
    {
        self::assertSame([], ClientLimits::problems(self::OPEN, ['web_domain' => 99], ClientLimits::need('sftp', 1000), 'The client'));
    }

    public function testEachLimitSaysWhatIsMissing(): void
    {
        $limits = ['limit_web_domain' => 7, 'limit_database' => 10, 'limit_shell_user' => 0, 'limit_web_quota' => 1000] + self::OPEN;
        $used = ['web_domain' => 7, 'database' => 3, 'shell_user' => 0, 'web_quota' => 600];

        self::assertSame([
            'The client has 0 websites left, the site needs 1',
            'The client has 0 shell users left, the site needs 1',
            'The client has 400 MB of web space left, the site needs 500',
        ], ClientLimits::problems($limits, $used, ClientLimits::need('sftp', 500), 'The client'));
        // FTP needs no shell user.
        self::assertCount(2, ClientLimits::problems($limits, $used, ClientLimits::need('ftp', 500), 'The client'));
    }

    public function testSftpNeedsJailedShellUsers(): void
    {
        $limits = ['ssh_chroot' => 'no'] + self::OPEN;
        self::assertStringContainsString('choose FTP', ClientLimits::problems($limits, [], ClientLimits::need('sftp', 0), 'The reseller')[0]);
        self::assertSame([], ClientLimits::problems($limits, [], ClientLimits::need('ftp', 0), 'The reseller'));
    }

    public function testTheWebSpaceOfANewSite(): void
    {
        self::assertSame(-1, ClientLimits::quota(self::OPEN, 0, -1), 'no limit: the default');
        self::assertSame(1000, ClientLimits::quota(['limit_web_quota' => 1000] + self::OPEN, 0, -1), 'unlimited default: what is left');
        self::assertSame(800, ClientLimits::quota(['limit_web_quota' => 5000] + self::OPEN, 0, 800), 'the default when it fits');
        self::assertSame(600, ClientLimits::quota(['limit_web_quota' => 1000] + self::OPEN, 400, 800), 'never more than what is left');
        self::assertNull(ClientLimits::quota(['limit_web_quota' => 1000] + self::OPEN, 600, -1), 'less than 500 MB left');
    }

    public function testTheWebServersOfAClient(): void
    {
        self::assertSame([1, 3], ClientLimits::webServers(['web_servers' => '1,3']));
        self::assertSame([2], ClientLimits::webServers(['web_servers' => '', 'default_webserver' => 2]));
        self::assertSame([], ClientLimits::webServers(['web_servers' => '', 'default_webserver' => 0]));
    }
}
