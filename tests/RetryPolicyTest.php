<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    // Regression: three attempts one minute apart turned a short network problem into a
    // permanent error. docs/testing.md asks a job to survive a node offline for 10 minutes.
    public function testAttemptsCoverAnOutageOfTenMinutes(): void
    {
        self::assertGreaterThan(600, RetryPolicy::delayAfter(1) + RetryPolicy::delayAfter(2));
    }

    public function testDelayGrows(): void
    {
        self::assertSame(300, RetryPolicy::delayAfter(1));
        self::assertSame(1800, RetryPolicy::delayAfter(2));
        self::assertSame(1800, RetryPolicy::delayAfter(9));
    }

    public function testNewJobIsDue(): void
    {
        self::assertTrue(RetryPolicy::isDue(['attempts' => 0], 1000));
    }

    public function testJobWaitsUntilItsTime(): void
    {
        self::assertFalse(RetryPolicy::isDue(['not_before' => 1001], 1000));
        self::assertTrue(RetryPolicy::isDue(['not_before' => 1000], 1000));
    }
}
