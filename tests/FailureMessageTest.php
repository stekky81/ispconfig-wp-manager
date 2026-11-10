<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\FailureMessage;

final class FailureMessageTest extends TestCase
{
    /** @return array<string, array{string, string}> output of real failures, and the cause told to the admin */
    public static function failures(): array
    {
        return [
            // Seen in the VM, from WP-CLI without DNS.
            'no network' => [
                "Command failed (1): Error: RuntimeException: Failed to get url 'https://api.wordpress.org/core/version-check/1.7/?locale=it_IT': cURL error 28: Resolving timed out after 10048 milliseconds.",
                'The node cannot reach api.wordpress.org: check its internet connection and DNS.',
            ],
            'no route' => ['Error: cURL error 7: Failed to connect', 'The node cannot reach the internet: check its connection and DNS.'],
            // Seen in the VM: the plugin name was only in a warning, which was dropped.
            'missing plugin' => [
                "Command failed (1): Error: No plugins installed.\nWarning: Couldn't find 'wpm-no-such-plugin-xyz' in the WordPress.org plugin directory.",
                'The plugin wpm-no-such-plugin-xyz does not exist on wordpress.org: check its name.',
            ],
            // Seen in the VM, with WP-CLI 2.12.
            'plugin not found' => [
                "Command failed (1): Error: No plugins installed.\nWarning: wpm-no-such-plugin-xyz: Plugin not found.\nWarning: The 'wpm-no-such-plugin-xyz' plugin could not be found.",
                'The plugin wpm-no-such-plugin-xyz does not exist on wordpress.org: check its name.',
            ],
            'select database' => [
                'Command failed (1): Error: Can’t select database. We were able to connect to the database server.',
                'WordPress cannot connect to its database: check that the database and its user exist on the node and that the database server runs.',
            ],
            'missing theme' => [
                "Command failed (1): Error: No themes installed.\nWarning: Couldn't find 'nope' in the WordPress.org theme directory.",
                'The theme nope does not exist on wordpress.org: check its name.',
            ],
            'database' => [
                'Command failed (1): Error: Error establishing a database connection.',
                'WordPress cannot connect to its database: check that the database and its user exist on the node and that the database server runs.',
            ],
            'database user' => [
                "Command failed (1): ERROR 1045 (28000): Access denied for user 'c1_x'@'localhost' (using password: YES)",
                'WordPress cannot connect to its database: check that the database and its user exist on the node and that the database server runs.',
            ],
            'disk' => ['Command failed (2): tar: ./wp-content/uploads/a.jpg: Cannot write: No space left on device', 'The disk of the node or the disk quota of the website is full.'],
            'quota' => ['Command failed (1): Disk quota exceeded', 'The disk of the node or the disk quota of the website is full.'],
            'timeout' => ["Timeout: 'sudo' '-u' 'web7' '--' 'tar' '-cz'", 'It took longer than allowed and was stopped.'],
            'no wp-cli' => [
                'Command failed (1): sudo: /usr/local/bin/wp: command not found',
                'WP-CLI is missing on the node: install it where wp_cli in config.php says (usually /usr/local/bin/wp).',
            ],
        ];
    }

    /** @dataProvider failures */
    public function testAKnownFailureSaysItsCauseAndKeepsTheDetails(string $raw, string $cause): void
    {
        self::assertSame($cause . ' Details: ' . $raw, FailureMessage::explain($raw));
    }

    public function testAnUnknownFailureKeepsItsOwnText(): void
    {
        self::assertSame('Command failed (1): Error: something new', FailureMessage::explain("Command failed (1): Error: something new\n"));
    }

    public function testAMessageIsExplainedOnce(): void
    {
        $once = FailureMessage::explain('Command failed (1): Disk quota exceeded');
        self::assertSame($once, FailureMessage::explain($once));
        self::assertSame('Copy of the files failed: ' . $once, FailureMessage::step('Copy of the files', 'Command failed (1): Disk quota exceeded'));
    }
}
