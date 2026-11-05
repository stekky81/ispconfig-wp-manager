<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\SiteStatus;

final class SiteStatusTest extends TestCase
{
    public function testErrorAndRetryMessagesAreShown(): void
    {
        self::assertSame('Refusing to install', SiteStatus::summary('error', 'Refusing to install'));
        self::assertSame('Attempt 1 failed', SiteStatus::summary('queued', 'Attempt 1 failed'));
    }

    // The message of an installed site is the full command log: not a status to show in a list.
    public function testOtherStatusesShowNothing(): void
    {
        self::assertSame('', SiteStatus::summary('installed', "wp core download\nwp config create"));
        self::assertSame('', SiteStatus::summary('installing', 'anything'));
    }

    public function testMissingMessageIsEmpty(): void
    {
        self::assertSame('', SiteStatus::summary('error', null));
        self::assertSame('', SiteStatus::summary('queued', "  \n "));
    }

    public function testWhitespaceIsCollapsedAndLongTextShortened(): void
    {
        self::assertSame('a b c', SiteStatus::summary('error', "a\n  b\tc"));

        $summary = SiteStatus::summary('error', str_repeat('è', 500));
        self::assertSame(201, mb_strlen($summary));
        self::assertStringEndsWith('…', $summary);
    }
}
