<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\ObjectReplacer;
use WpManager\SearchReplacePlan;
use WpManager\SiteCaches;

final class ObjectReplacerTest extends TestCase
{
    private static function plan(): SearchReplacePlan
    {
        return new SearchReplacePlan('http://old.com', 'http://new.test', '/var/www/clients/client1/web7', '/var/www/clients/client2/web9');
    }

    private static function hex(string ...$cells): string
    {
        return implode("\t", array_map(static fn(string $cell): string => strtoupper(bin2hex($cell)), $cells));
    }

    /** Lines as the mysql client prints the schema query. */
    private static function schema(): string
    {
        return implode("\n", [
            self::hex('wp_options', 'option_id') . "\tbigint\t1",
            self::hex('wp_options', 'option_name') . "\tvarchar\t0",
            self::hex('wp_options', 'option_value') . "\tlongtext\t0",
            self::hex('wp_posts', 'ID') . "\tbigint\t1",
            self::hex('wp_posts', 'post_content') . "\tlongtext\t0",
            self::hex('wp_posts', 'guid') . "\tvarchar\t0",
            self::hex('wp_posts', 'post_author') . "\tbigint\t0",
            self::hex('nokey', 'value') . "\ttext\t0",
            self::hex('wc_sessions', 'session_key') . "\tchar\t1",
            self::hex('wc_sessions', 'session_value') . "\tlongtext\t0",
            self::hex('odd', 'k') . "\tdatetime\t1",
            self::hex('odd', 'v') . "\ttext\t0",
            self::hex("we`ird", "co`l") . "\tint\t1",
            self::hex("we`ird", "va`l") . "\ttext\t0",
        ]) . "\n";
    }

    public function testOnlyTablesWithAUsableKeyAndTextColumnsAreUsed(): void
    {
        $tables = ObjectReplacer::parseSchema(self::schema());

        self::assertSame(['wp_options', 'wp_posts', 'wc_sessions', 'we`ird'], array_keys($tables));
        self::assertSame([['option_id', true]], $tables['wp_options']['keys']);
        self::assertSame(['option_name', 'option_value'], $tables['wp_options']['columns']);
        // As WP-CLI: guid stays as it is. Numbers are not text.
        self::assertSame(['post_content'], $tables['wp_posts']['columns']);
        self::assertSame([['session_key', false]], $tables['wc_sessions']['keys']);
    }

    public function testTheSearchOnlyLooksAtSerializedValuesThatMentionTheOldSite(): void
    {
        $sql = ObjectReplacer::selectSql(ObjectReplacer::parseSchema(self::schema()), self::plan()->needles());

        self::assertStringContainsString("SELECT 0, 1, HEX(CAST(`option_id` AS CHAR)), HEX(`option_value`) FROM `wp_options` WHERE (`option_value` LIKE 'a:%' OR `option_value` LIKE 'O:%' OR `option_value` LIKE 's:%') AND (`option_value` LIKE '%old.com%' OR `option_value` LIKE '%/var/www/clients/client1/web7%');", $sql);
        self::assertStringContainsString('SELECT 2, 0, HEX(`session_key`), HEX(`session_value`) FROM `wc_sessions`', $sql);
        // A name of the site stays one name.
        self::assertStringContainsString('FROM `we``ird`', $sql);
        self::assertStringContainsString('HEX(`va``l`)', $sql);
    }

    public function testNeedlesThatCannotBeWrittenSafelyAreNotSearched(): void
    {
        $tables = ObjectReplacer::parseSchema(self::schema());
        self::assertSame('', ObjectReplacer::selectSql($tables, ["x' OR 1=1 -- ", 'a%b', 'ab']));
        self::assertStringContainsString("LIKE '%/srv/web\\_7%'", ObjectReplacer::selectSql($tables, ['/srv/web_7']));
    }

    public function testAnObjectThatStillMentionsTheOldSiteIsUpdatedByItsKey(): void
    {
        $tables = ObjectReplacer::parseSchema(self::schema());
        $old = 'a:1:{s:4:"rate";O:16:"WC_Shipping_Rate":1:{s:4:"link";s:20:"http://old.com/shop/";}}';
        $new = 'a:1:{s:4:"rate";O:16:"WC_Shipping_Rate":1:{s:4:"link";s:21:"http://new.test/shop/";}}';
        $rows = "0\t1\t" . self::hex('412', $old) . "\n";

        self::assertSame(
            ["UPDATE `wp_options` SET `option_value` = X'" . bin2hex($new) . "' WHERE `option_id` = 412 AND `option_value` = X'" . bin2hex($old) . "';"],
            ObjectReplacer::updates($tables, $rows, self::plan()),
        );
    }

    public function testATextKeyIsMatchedAsItsBytes(): void
    {
        $tables = ObjectReplacer::parseSchema(self::schema());
        $rows = "2\t0\t" . self::hex("k'1", 's:14:"http://old.com";') . "\n";

        $updates = ObjectReplacer::updates($tables, $rows, self::plan());
        self::assertCount(1, $updates);
        self::assertStringContainsString("WHERE `session_key` = X'" . strtoupper(bin2hex("k'1")) . "' AND", $updates[0]);
    }

    /** @return array<string, array{string}> */
    public static function nothingToDo(): array
    {
        $value = 's:14:"http://old.com";';
        return [
            'already right, as an email address' => ["0\t1\t" . self::hex('1', 's:12:"info@old.com";')],
            'not serialized' => ["0\t1\t" . self::hex('1', 'see http://old.com')],
            'broken lengths' => ["0\t1\t" . self::hex('1', 's:3:"http://old.com";')],
            'a key that is not a number' => ["0\t1\t" . self::hex('1 OR 1', $value)],
            'an unknown table' => ["9\t0\t" . self::hex('1', $value)],
            'an unknown column' => ["0\t9\t" . self::hex('1', $value)],
            'missing cells' => ["0\t1\t" . self::hex($value)],
            'not hex' => ["0\t1\t31\tNULL"],
            'a mysql warning' => ['Warning: something'],
            'nothing' => [''],
        ];
    }

    /** @dataProvider nothingToDo */
    public function testRowsThatNeedNoChangeOrCannotBeReadAreLeftAlone(string $rows): void
    {
        self::assertSame([], ObjectReplacer::updates(ObjectReplacer::parseSchema(self::schema()), $rows, self::plan()));
    }

    public function testCachesOfPagesAreDeletedWithThePrefixOfTheSite(): void
    {
        $sql = SiteCaches::clearSql('wp2_');
        self::assertStringContainsString("DELETE FROM `wp2_options` WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name IN ('_elementor_global_css');", $sql);
        // Regression (VM, Elementor): its cache keeps HTML in base64, out of reach of any replace.
        self::assertStringContainsString("DELETE FROM `wp2_postmeta` WHERE meta_key IN ('_elementor_element_cache', '_elementor_css');", $sql);
    }

    public function testAnUnsafePrefixIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        SiteCaches::clearSql('wp_`; DROP TABLE x; --');
    }
}
