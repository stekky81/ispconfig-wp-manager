<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Readable list of the Remote API calls of a dry run, shown to the admin on the form.
 * It expects IspconfigApi::calls(), where passwords and the session id are already masked.
 */
final class DryRunReport
{
    /**
     * @param list<array{method:string, params:array}> $calls
     * @return list<string> one line per call
     */
    public static function lines(array $calls): array
    {
        return array_map(
            static fn(array $call): string => $call['method'] . ' ' . json_encode(
                $call['params'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
            $calls,
        );
    }
}
