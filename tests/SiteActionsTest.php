<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests;

use PHPUnit\Framework\TestCase;
use WPStation\SiteActions;

final class SiteActionsTest extends TestCase
{
    private static function site(): array
    {
        return [
            'updates_json' => json_encode([
                'plugins' => [
                    ['name' => 'akismet', 'status' => 'inactive'],
                    ['name' => 'woocommerce', 'status' => 'active'],
                    ['name' => 'object-cache', 'status' => 'dropin'],
                    ['name' => '--require=x', 'status' => 'inactive'],
                ],
                'themes' => [
                    ['name' => 'Divi', 'status' => 'parent'],
                    ['name' => 'divi-child', 'status' => 'active'],
                    ['name' => 'twentytwentyfive', 'status' => 'inactive'],
                ],
                'admins' => [['id' => 1, 'login' => 'madmin'], ['id' => 5, 'login' => 'editor']],
            ]),
            'backups_json' => json_encode([['id' => '20260101-000000'], ['id' => '20260102-000000']]),
        ];
    }

    public function testThePanelOffersOnlyWhatTheLastCheckSaw(): void
    {
        self::assertSame(['akismet'], SiteActions::allowedParams('plugin_activate', self::site()));
        self::assertSame(['woocommerce'], SiteActions::allowedParams('plugin_deactivate', self::site()));
        self::assertSame(['akismet', 'woocommerce'], SiteActions::allowedParams('plugin_delete', self::site()));
        self::assertSame(['Divi', 'twentytwentyfive'], SiteActions::allowedParams('theme_activate', self::site()));
        self::assertSame(['twentytwentyfive'], SiteActions::allowedParams('theme_delete', self::site()));
        self::assertSame(['1', '5'], SiteActions::allowedParams('password', self::site()));
        self::assertSame(['20260101-000000', '20260102-000000'], SiteActions::allowedParams('restore', self::site()));
        self::assertSame(['on', 'off'], SiteActions::allowedParams('maintenance', self::site()));
        self::assertSame([''], SiteActions::allowedParams('login', self::site()));
    }

    public function testASiteNeverCheckedOffersNothingToChoose(): void
    {
        $site = ['updates_json' => null, 'backups_json' => null];
        self::assertSame([], SiteActions::allowedParams('plugin_activate', $site));
        self::assertSame([], SiteActions::allowedParams('password', $site));
        self::assertSame([], SiteActions::allowedParams('restore', $site));
        self::assertSame([], SiteActions::allowedParams('push', $site), 'not an action of the site page');
    }

    public function testEveryActionOfTheSitePageIsQueuedOnTheNode(): void
    {
        self::assertSame([], array_diff(SiteActions::SITE_PAGE, SiteActions::ALL));
        foreach (SiteActions::ALL as $action) {
            self::assertLessThanOrEqual(20, strlen($action), 'fits action_request');
        }
    }
}
