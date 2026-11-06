<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\SearchReplacePlan;

final class SearchReplacePlanTest extends TestCase
{
    private function plan(string $old = 'https://www.old-site.com', string $new = 'http://clone.test'): SearchReplacePlan
    {
        return new SearchReplacePlan($old, $new, '/var/www/clients/client1/web7', '/var/www/clients/client2/web9');
    }

    /** Applies the rules the way WP-CLI does with --regex and --regex-delimiter=#. */
    private function apply(string $text, ?SearchReplacePlan $plan = null): string
    {
        foreach (($plan ?? $this->plan())->rules() as [$pattern, $replacement]) {
            $text = (string)preg_replace('#' . $pattern . '#i', $replacement, $text);
        }
        return $text;
    }

    /** @dataProvider urls */
    public function testEveryFormOfTheUrlIsReplaced(string $before, string $after): void
    {
        self::assertSame($after, $this->apply($before));
    }

    public static function urls(): array
    {
        return [
            'https with www' => ['https://www.old-site.com/page', 'http://clone.test/page'],
            'https' => ['https://old-site.com/page', 'http://clone.test/page'],
            'http' => ['http://old-site.com', 'http://clone.test'],
            'http with www' => ['http://www.old-site.com/a?b=1', 'http://clone.test/a?b=1'],
            'upper case' => ['HTTPS://WWW.OLD-SITE.COM/x', 'http://clone.test/x'],
            'no scheme' => ['<img src="//old-site.com/a.jpg">', '<img src="//clone.test/a.jpg">'],
            'no scheme with www' => ['url(//www.old-site.com/a.png)', 'url(//clone.test/a.png)'],
            'json escaped' => ['{"url":"https:\/\/old-site.com\/a.jpg"}', '{"url":"http:\/\/clone.test\/a.jpg"}'],
            'json escaped with www' => ['"http:\/\/www.old-site.com"', '"http:\/\/clone.test"'],
            'url encoded' => ['u=https%3A%2F%2Fold-site.com%2Fa', 'u=http%3A%2F%2Fclone.test%2Fa'],
            'url encoded lower case' => ['u=https%3a%2f%2fwww.old-site.com', 'u=http%3A%2F%2Fclone.test'],
            'at the end of a sentence' => ['See https://old-site.com.', 'See http://clone.test.'],
            'with a port' => ['https://old-site.com:8080/x', 'http://clone.test:8080/x'],
            'path on disk' => ['/var/www/clients/client1/web7/web/x.log', '/var/www/clients/client2/web9/web/x.log'],
        ];
    }

    /** @dataProvider leftAlone */
    public function testOtherTextIsLeftAlone(string $text): void
    {
        self::assertSame($text, $this->apply($text));
    }

    public static function leftAlone(): array
    {
        return [
            'longer domain' => ['https://old-site.company.it/page'],
            'longer tld' => ['https://old-site.com.evil.org/'],
            'another domain ending the same' => ['https://my-old-site.com/x'],
            'subdomain of the old site' => ['https://shop.old-site.com/x'],
            'email address' => ['Write to info@old-site.com'],
            'bare domain in a text' => ['Welcome to old-site.com'],
            'similar path' => ['/var/www/clients/client1/web70/web'],
        ];
    }

    // The trap of serialized data: a plain replace in the raw string would break the lengths.
    public function testRulesKeepSerializedDataValidWhenAppliedToTheValues(): void
    {
        $data = ['url' => 'https://old-site.com/logo.png', 'nested' => ['a' => ['b' => 'http://www.old-site.com']]];
        array_walk_recursive($data, function (string &$value): void {
            $value = $this->apply($value);
        });

        $restored = unserialize(serialize($data));
        self::assertSame('http://clone.test/logo.png', $restored['url']);
        self::assertSame('http://clone.test', $restored['nested']['a']['b']);
    }

    public function testReplacementsWithSpecialCharactersStayLiteral(): void
    {
        $plan = new SearchReplacePlan('https://old-site.com', 'https://a.test/$1\\2', '/var/www/clients/client1/web7', '/var/www/clients/client1/web9');

        self::assertSame('https://a.test/$1\\2/x', $this->apply('https://old-site.com/x', $plan));
    }

    public function testASubfolderOfTheNewSiteIsKept(): void
    {
        self::assertSame('http://clone.test/blog/x', $this->apply('https://old-site.com/x', $this->plan('https://old-site.com', 'http://clone.test/blog')));
    }

    public function testASubfolderOfTheOldSiteStaysInTheText(): void
    {
        self::assertSame('http://clone.test/blog/x', $this->apply('https://old-site.com/blog/x', $this->plan('https://old-site.com/blog')));
    }

    public function testNeedlesFindWhatRegexCanNotReach(): void
    {
        self::assertSame('old-site.com', $this->plan()->oldHost());

        $encoded = base64_encode('a:1:{s:3:"url";s:24:"https://old-site.com/img";}');
        $found = array_filter($this->plan()->base64Needles(), static fn(string $needle): bool => str_contains($encoded, $needle));

        self::assertNotEmpty($found);
    }

    public function testNeedlesMatchWhateverTheOffset(): void
    {
        foreach (['', 'a', 'ab'] as $prefix) {
            $encoded = base64_encode($prefix . 'old-site.com');
            $found = array_filter($this->plan()->base64Needles(), static fn(string $needle): bool => str_contains($encoded, $needle));
            self::assertNotEmpty($found, "offset of {$prefix}");
        }
    }

    // Regression: the home option belongs to the site owner and reached WP-CLI as an argument.
    /** @dataProvider hostileUrls */
    public function testAHostileAddressIsRefused(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchReplacePlan($url, 'http://a.test', '/var/www/a', '/var/www/b');
    }

    public static function hostileUrls(): array
    {
        return [
            'option' => ['http://--require=evil.php'],
            'option with path' => ['http://--exec=x.y/'],
            'shell characters' => ['http://a.b;id/'],
        ];
    }

    public function testAnUnusableUrlIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchReplacePlan('not a url', 'http://a.test', '/a', '/b');
    }
}
