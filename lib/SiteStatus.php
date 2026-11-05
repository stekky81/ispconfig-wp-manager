<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Short explanation of a site status for the panel. The result is plain text: escape it for HTML.
 */
final class SiteStatus
{
    /** What went wrong in the last action on the site, on one line. Finished actions show nothing. */
    public static function actionProblem(string $status, ?string $message, int $maxLength = 200): string
    {
        return in_array($status, ['rolled_back', 'rollback_failed', 'refused', 'failed'], true)
            ? self::summary('error', $message, $maxLength)
            : '';
    }

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
