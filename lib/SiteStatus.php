<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Short explanation of a site status for the panel. The result is plain text: escape it for HTML.
 */
final class SiteStatus
{
    /** The reason behind an error or a pending retry, on one line. */
    public static function summary(string $status, ?string $message, int $maxLength = 200): string
    {
        if (!in_array($status, ['error', 'queued'], true)) {
            return '';
        }
        $text = trim((string)preg_replace('/\s+/u', ' ', (string)$message));
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength) . '…' : $text;
    }
}
