<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\SiteRemoval;

final class SiteRemovalTest extends TestCase
{
    private static function site(array $changes = []): array
    {
        return $changes + ['wpm_site_id' => 4, 'domain_id' => 12, 'domain' => 'a.test', 'status' => 'installed', 'action_request' => '', 'db_name' => 'c1_a'];
    }

    private static function web(): array
    {
        return ['domain_id' => 12, 'domain' => 'a.test', 'type' => 'vhost'];
    }

    public function testAnIdleSiteCanBeRemoved(): void
    {
        self::assertSame('', SiteRemoval::refusal(self::site(), self::web(), 0, 0));
        self::assertSame('', SiteRemoval::refusal(self::site(['status' => 'error']), self::web(), 0, 0));
    }

    /** @return array<string, array{array, ?array, int, int, string}> */
    public static function refused(): array
    {
        return [
            'still installing' => [self::site(['status' => 'installing']), self::web(), 0, 0, 'installed'],
            'queued' => [self::site(['status' => 'queued']), self::web(), 0, 0, 'installed'],
            'an action runs' => [self::site(['action_request' => 'backup']), self::web(), 0, 0, 'action'],
            'with a staging' => [self::site(), self::web(), 1, 0, 'staging'],
            'a clone of it is being installed' => [self::site(), self::web(), 0, 1, 'clone'],
            'website gone' => [self::site(), null, 0, 0, 'no longer'],
            'not a website' => [self::site(), ['type' => 'alias'] + self::web(), 0, 0, 'no longer'],
            'another website' => [self::site(), ['domain' => 'b.test'] + self::web(), 0, 0, 'does not match'],
        ];
    }

    /** @dataProvider refused */
    public function testWhatStopsARemoval(array $site, ?array $web, int $stagings, int $clones, string $reason): void
    {
        self::assertStringContainsString($reason, SiteRemoval::refusal($site, $web, $stagings, $clones));
    }

    public function testEverythingOfTheWebsiteGoesAndTheWebsiteLast(): void
    {
        $children = [
            'web_domain' => [['domain_id' => 13, 'domain' => 'www2.a.test', 'type' => 'alias'], ['domain_id' => 12, 'domain' => 'a.test', 'type' => 'vhost']],
            'ftp_user' => [['ftp_user_id' => 5, 'username' => 'c1_a']],
            'shell_user' => [['shell_user_id' => 6, 'username' => 'c1_shell']],
            'cron' => [['id' => 7, 'command' => 'http://a.test/wp-cron.php']],
            'webdav_user' => [],
            'web_folder' => [['web_folder_id' => 8, 'path' => '/private']],
            'web_folder_user' => [['web_folder_user_id' => 9, 'username' => 'guest']],
            'web_backup' => [['backup_id' => 10, 'filename' => 'web12.tar.gz']],
            'web_database' => [['database_id' => 11, 'database_name' => 'c1_a', 'database_user_id' => 2, 'database_ro_user_id' => 3]],
            'web_database_user' => [['database_user_id' => 2, 'database_user' => 'c1_a'], ['database_user_id' => 3, 'database_user' => 'c1_shared']],
        ];
        $plan = SiteRemoval::plan(self::web(), $children, [3]);
        $steps = array_map(static fn(array $item): string => "{$item['table']} {$item['id']}", $plan);

        self::assertSame([
            'web_domain 13', 'ftp_user 5', 'shell_user 6', 'cron 7', 'web_folder_user 9', 'web_folder 8',
            'web_backup 10', 'web_database 11', 'web_database_user 2', 'web_domain 12',
        ], $steps, 'the shared user 3 stays; the website itself only once, at the end');
        self::assertSame('c1_a', $plan[7]['label']);
    }

    public function testTheDatabaseUsersOfTheSite(): void
    {
        $databases = [
            ['database_name' => 'c1_a', 'database_user_id' => 2, 'database_ro_user_id' => 0],
            ['database_name' => 'c1_b', 'database_user_id' => 2, 'database_ro_user_id' => 5],
        ];
        self::assertSame([2, 5], SiteRemoval::databaseUsers($databases));
    }

    public function testADatabaseOfWordPressThatIsNotAttachedStays(): void
    {
        self::assertSame([], SiteRemoval::notAttached(self::site(), [['database_name' => 'c1_a']]));
        self::assertSame(['c1_a'], SiteRemoval::notAttached(self::site(), [['database_name' => 'c1_other']]));
        self::assertSame([], SiteRemoval::notAttached(self::site(['db_name' => '']), []));
    }
}
