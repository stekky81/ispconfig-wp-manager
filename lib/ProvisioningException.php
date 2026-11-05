<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Provisioning failed. Everything created so far was rolled back, except $leftovers,
 * which must be removed by hand and are shown to the admin.
 */
final class ProvisioningException extends \RuntimeException
{
    /** @param list<string> $leftovers */
    public function __construct(string $message, public readonly array $leftovers, ?\Throwable $previous = null)
    {
        if ($leftovers !== []) {
            $message .= ' — rollback incomplete, remove manually: ' . implode(', ', $leftovers);
        }
        parent::__construct($message, 0, $previous);
    }
}
