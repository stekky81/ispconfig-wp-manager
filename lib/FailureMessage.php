<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Turns the output of a failed command into a sentence for the admin: the likely cause and what to do,
 * then the original text as details. Unknown failures keep their own text. Messages of WordPress
 * come in the language of the site: only the English ones are recognised.
 */
final class FailureMessage
{
    private const DETAILS = ' Details: ';

    public static function explain(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || str_contains($raw, self::DETAILS)) {
            return $raw;
        }
        $cause = self::cause($raw);
        return $cause === '' ? $raw : $cause . self::DETAILS . $raw;
    }

    /** "What failed: why." for a step of a longer job. */
    public static function step(string $what, string $raw): string
    {
        return $what . ' failed: ' . self::explain($raw);
    }

    private static function cause(string $raw): string
    {
        if (preg_match("/Couldn't find '([^']{1,100})' in the WordPress\\.org (plugin|theme) directory/i", $raw, $m)
            || preg_match('/Warning: ([a-z0-9_-]{1,100}): (plugin|theme) not found/i', $raw, $m)) {
            return 'The ' . strtolower($m[2]) . " {$m[1]} does not exist on wordpress.org: check its name.";
        }
        if (preg_match('/^Timeout: /', $raw)) {
            return 'It took longer than allowed and was stopped.';
        }
        if (preg_match('/No space left on device|Disk quota exceeded/i', $raw)) {
            return 'The disk of the node or the disk quota of the website is full.';
        }
        if (preg_match('/Failed to get url \'https?:\/\/([^\/\']{1,100})|Could not resolve host:? ([^\s;,]{1,100})/i', $raw, $m)) {
            $host = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            return "The node cannot reach {$host}: check its internet connection and DNS.";
        }
        if (preg_match('/cURL error (6|7|28|35)\b|Connection timed out|Resolving timed out/i', $raw)) {
            return 'The node cannot reach the internet: check its connection and DNS.';
        }
        if (preg_match("/Error establishing a database connection|Can(?:'|’)t select database|Access denied for user|Unknown database|Can't connect to (local )?(MySQL|MariaDB) server/i", $raw)) {
            return 'WordPress cannot connect to its database: check that the database and its user exist on the node and that the database server runs.';
        }
        if (preg_match('/(\/wp|wp-cli)[^\n]{0,40}(command not found|No such file or directory)/i', $raw)) {
            return 'WP-CLI is missing on the node: install it where wp_cli in config.php says (usually /usr/local/bin/wp).';
        }
        return '';
    }
}
