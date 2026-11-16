<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\Settings;
use WpManager\ValidationException;

final class SettingsTest extends TestCase
{
    private function input(array $over = []): array
    {
        return $over + [
            'server_php_id' => '0',
            'hd_quota' => '-1',
            'traffic_quota' => '2000',
            'ssl' => 'y',
            'ssl_letsencrypt' => 'n',
            'subdomain' => 'www',
            'locale' => 'en_GB',
            'plugins' => 'akismet, jetpack',
            'theme' => '',
            'auto_updates' => 'y',
            'notify_recipients' => '',
            'notify_summary' => 'weekly',
            'notify_site_ready' => 'y',
        ];
    }

    public function testInputBecomesConfigOverrides(): void
    {
        $config = Config::fromArray([])->withOverrides(Settings::fromInput($this->input()));

        self::assertSame('en_GB', $config->get('wordpress.locale'));
        self::assertSame(['akismet', 'jetpack'], $config->get('wordpress.plugins'));
        self::assertSame(2000, $config->get('site_defaults.traffic_quota'));
        self::assertSame('n', $config->get('site_defaults.ssl_letsencrypt'));
    }

    public function testEmptyPluginListRemovesTheDefaults(): void
    {
        $base = Config::fromArray(['wordpress' => ['plugins' => ['wordfence']]]);
        $config = $base->withOverrides(Settings::fromInput($this->input(['plugins' => ''])));

        self::assertSame([], $config->get('wordpress.plugins'));
    }

    // dry_run protects real servers: it can only be changed in config.php.
    public function testSettingsCanNotChangeProtectedKeys(): void
    {
        $overrides = Settings::fromInput($this->input([
            'dry_run' => 'n',
            'remote_api' => ['url' => 'https://evil.example'],
            'key_file' => '/tmp/key',
        ]));

        self::assertSame(['site_defaults', 'wordpress', 'updates', 'notifications'], array_keys($overrides));
        $config = Config::fromArray(['dry_run' => true])->withOverrides($overrides);
        self::assertTrue($config->isDryRun());
    }

    public function testStoredSettingsAreFilteredToo(): void
    {
        $stored = json_encode(['dry_run' => false, 'wordpress' => ['locale' => 'de_DE', 'constants' => ['X' => ['1', true]]]]);
        $config = Config::fromArray(['dry_run' => true])->withOverrides(Settings::fromJson((string)$stored));

        self::assertTrue($config->isDryRun());
        self::assertSame('de_DE', $config->get('wordpress.locale'));
        self::assertArrayNotHasKey('X', $config->get('wordpress.constants'));
    }

    public function testAutomaticUpdatesAreOnUnlessTurnedOff(): void
    {
        self::assertSame('y', Config::fromArray([])->get('updates.auto'));

        $off = Settings::fromInput($this->input(['auto_updates' => 'n']));
        self::assertSame('n', Config::fromArray([])->withOverrides(Settings::fromJson((string)json_encode($off)))->get('updates.auto'));
    }

    public function testNotificationsAreSavedNormalised(): void
    {
        $stored = json_encode(Settings::fromInput($this->input([
            'notify_recipients' => " ops@example.com;  Me@Example.com,ops@example.com\n",
            'notify_summary' => 'daily',
            'notify_site_ready' => 'n',
        ])));
        $config = Config::fromArray([])->withOverrides(Settings::fromJson((string)$stored));

        self::assertSame('ops@example.com, Me@Example.com', $config->get('notifications.recipients'));
        self::assertSame('daily', $config->get('notifications.summary'));
        self::assertSame('n', $config->get('notifications.site_ready'));
    }

    public function testNotificationsDefaultToAWeeklySummaryForTheIspconfigAdmin(): void
    {
        self::assertSame(['recipients' => '', 'summary' => 'weekly', 'site_ready' => 'y'], Config::fromArray([])->get('notifications'));
    }

    public function testBrokenStoredSettingsAreIgnored(): void
    {
        self::assertSame([], Settings::fromJson('not json'));
        self::assertSame([], Settings::fromJson(''));
    }

    /** @dataProvider invalidInput */
    public function testInvalidValuesAreRejected(array $over): void
    {
        $this->expectException(ValidationException::class);
        Settings::fromInput($this->input($over));
    }

    public static function invalidInput(): array
    {
        return [
            'quota' => [['hd_quota' => '-2']],
            'quota text' => [['traffic_quota' => '1; drop']],
            'ssl' => [['ssl' => 'maybe']],
            'letsencrypt' => [['ssl_letsencrypt' => '']],
            'subdomain' => [['subdomain' => 'evil']],
            'locale' => [['locale' => 'it;id']],
            'plugin' => [['plugins' => '--require=/tmp/x.php']],
            'theme' => [['theme' => '../x']],
            'php' => [['server_php_id' => 'abc']],
            'auto updates' => [['auto_updates' => 'maybe']],
            'recipient' => [['notify_recipients' => 'not an address']],
            // A header injected through the address would add recipients or headers to every email.
            'recipient with a header' => [['notify_recipients' => "a@example.com\r\nBcc: x@evil.example"]],
            'too many recipients' => [['notify_recipients' => implode(',', array_map(static fn(int $i): string => "a{$i}@example.com", range(1, 11)))]],
            'summary' => [['notify_summary' => 'hourly']],
            'site ready' => [['notify_site_ready' => 'maybe']],
        ];
    }
}
