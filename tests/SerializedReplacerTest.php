<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\SearchReplacePlan;
use WpManager\SerializedReplacer;

final class SerializedReplacerTest extends TestCase
{
    private static function replacer(): SerializedReplacer
    {
        $plan = new SearchReplacePlan('http://old.com', 'https://www.new-site.test', '/var/www/clients/client1/web7', '/var/www/clients/client2/web9');
        return new SerializedReplacer($plan->apply(...));
    }

    /** Checked as PHP reads it, without creating any object. */
    private static function read(string $value): mixed
    {
        $data = unserialize($value, ['allowed_classes' => false]);
        self::assertNotFalse($data, "Not valid serialized data: {$value}");
        return $data;
    }

    // Regression (VM, WooCommerce): WP-CLI skips objects of classes that are not loaded.
    public function testAnObjectOfAPluginClassIsReplaced(): void
    {
        $value = 'a:1:{s:4:"rate";O:16:"WC_Shipping_Rate":1:{s:4:"link";s:20:"http://old.com/shop/";}}';
        $new = (string)self::replacer()->replace($value);

        self::assertSame('a:1:{s:4:"rate";O:16:"WC_Shipping_Rate":1:{s:4:"link";s:31:"https://www.new-site.test/shop/";}}', $new);
        self::read($new);
    }

    public function testProtectedAndPrivatePropertiesKeepTheirNames(): void
    {
        $value = "O:3:\"Foo\":2:{s:6:\"\0*\0url\";s:14:\"http://old.com\";s:8:\"\0Foo\0dir\";s:29:\"/var/www/clients/client1/web7\";}";
        $data = (array)self::read((string)self::replacer()->replace($value));

        self::assertSame('https://www.new-site.test', $data["\0*\0url"]);
        self::assertSame('/var/www/clients/client2/web9', $data["\0Foo\0dir"]);
    }

    public function testKeysAndClassNamesAreNeverChanged(): void
    {
        $value = serialize(['http://old.com' => 'http://old.com']);
        self::assertSame(['http://old.com' => 'https://www.new-site.test'], self::read((string)self::replacer()->replace($value)));
    }

    public function testEveryOtherTypeIsKept(): void
    {
        $data = [1, -2, 1.5, -0.25, 1.0E+25, true, false, null, 'ünïcødé http://old.com/ä', [], INF, -INF];
        $value = serialize($data);
        $new = (string)self::replacer()->replace($value);
        $data[8] = 'ünïcødé https://www.new-site.test/ä';
        self::assertSame($data, self::read($new));
    }

    public function testReferencesStayValid(): void
    {
        $inner = ['url' => 'http://old.com'];
        $value = serialize([&$inner, &$inner, (object)['a' => 'x']]);
        $new = (string)self::replacer()->replace($value);
        self::assertStringContainsString('R:2;', $new);
        self::assertSame('https://www.new-site.test', self::read($new)[1]['url']);
    }

    // WordPress serializes again a value that is serialized already.
    public function testDataSerializedTwiceIsReplacedInside(): void
    {
        $value = serialize(serialize(['url' => 'http://old.com/x']));
        $new = (string)self::replacer()->replace($value);
        self::assertSame(['url' => 'https://www.new-site.test/x'], unserialize((string)self::read($new), ['allowed_classes' => false]));
    }

    public function testAStringIsReplacedAndItsLengthWrittenAgain(): void
    {
        self::assertSame('s:25:"https://www.new-site.test";', self::replacer()->replace('s:14:"http://old.com";'));
    }

    public function testAnEnumIsKept(): void
    {
        self::assertSame('a:1:{i:0;E:11:"Suit:Hearts";}', self::replacer()->replace('a:1:{i:0;E:11:"Suit:Hearts";}'));
    }

    /** @return array<string, array{string}> */
    public static function unreadable(): array
    {
        return [
            'plain text' => ['http://old.com'],
            'wrong length' => ['s:5:"http://old.com";'],
            'length past the end' => ['s:99:"http://old.com";'],
            'more after the value' => ['s:14:"http://old.com";x'],
            'two values' => ['i:1;i:2;'],
            'fewer members than counted' => ['a:2:{i:0;s:14:"http://old.com";}'],
            'custom serialization' => ['C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}'],
            'escaped string' => ['S:14:"http://old.com";'],
            'missing brace' => ['a:1:{i:0;s:14:"http://old.com";'],
            'huge count' => ['a:99999999999:{}'],
            'empty' => [''],
            'json' => ['{"url":"http://old.com"}'],
            'array as key' => ['a:1:{a:0:{}i:1;}'],
            'unknown key type' => ['a:1:{x:1:"k";i:1;}'],
        ];
    }

    /** @dataProvider unreadable */
    public function testWhatIsNotEntirelySerializedDataIsLeftAlone(string $value): void
    {
        self::assertNull(self::replacer()->replace($value));
    }

    // A hostile value of a site must not crash the worker of root.
    public function testDeepValuesAreRefusedWithoutCrashing(): void
    {
        $deep = str_repeat('a:1:{i:0;', 100000) . 'N;' . str_repeat('}', 100000);
        self::assertNull(self::replacer()->replace($deep));

        $nested = 's:14:"http://old.com";';
        for ($i = 0; $i < 300; $i++) {
            $nested = serialize($nested);
        }
        // Not taken for plain text at the limit: that would break the lengths inside.
        self::assertNull(self::replacer()->replace('a:1:{i:0;' . $nested . '}'));
    }

    public function testValuesWithinTheLimitAreReplaced(): void
    {
        $value = serialize(array_reduce(range(1, 100), static fn(mixed $carry): array => [$carry], 'http://old.com'));
        self::assertStringContainsString('new-site.test', (string)self::replacer()->replace($value));
    }

    public function testTheReplacerCanBeUsedAgainAfterAFailure(): void
    {
        $replacer = self::replacer();
        self::assertNull($replacer->replace('s:99:"x";'));
        self::assertSame('s:25:"https://www.new-site.test";', $replacer->replace('s:14:"http://old.com";'));
    }
}
