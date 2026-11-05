<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * When a failed install job runs again. The waits are long enough to outlast a short
 * network or DNS problem, which would otherwise use up every attempt within minutes.
 */
final class RetryPolicy
{
    private const DELAYS = [1 => 300, 2 => 1800];

    /** Seconds to wait after the given number of failed attempts. */
    public static function delayAfter(int $attempts): int
    {
        return self::DELAYS[$attempts] ?? max(self::DELAYS);
    }

    public static function isDue(array $job, int $now): bool
    {
        return (int)($job['not_before'] ?? 0) <= $now;
    }
}
