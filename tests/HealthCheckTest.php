<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\HealthCheck;

final class HealthCheckTest extends TestCase
{
    private function fetcher(array $answers): \Closure
    {
        return static fn(string $url): array => $answers[parse_url($url, PHP_URL_PATH)] ?? ['code' => 0, 'body' => ''];
    }

    private function healthy(array $over = []): array
    {
        return $over + [
            '/' => ['code' => 200, 'body' => '<html>ok</html>'],
            '/wp-login.php' => ['code' => 200, 'body' => '<input name="log">'],
        ];
    }

    private function check(array $answers): array
    {
        return (new HealthCheck($this->fetcher($answers)))->run(['site_url' => 'http://a.test', 'domain' => 'a.test']);
    }

    public function testHealthySite(): void
    {
        self::assertSame(['home' => true, 'login' => true], $this->check($this->healthy()));
    }

    public function testRedirectsAreHealthy(): void
    {
        self::assertTrue($this->check($this->healthy(['/' => ['code' => 301, 'body' => '']]))['home']);
    }

    /** @dataProvider brokenHomes */
    public function testBrokenHome(int $code, string $body): void
    {
        self::assertFalse($this->check($this->healthy(['/' => ['code' => $code, 'body' => $body]]))['home']);
    }

    public static function brokenHomes(): array
    {
        return [
            'server error' => [500, 'There has been a critical error on this website.'],
            'no answer' => [0, ''],
            'not found' => [404, ''],
            'php fatal with status 200' => [200, '<b>Fatal error</b>: Uncaught Error in /x.php'],
            'database error' => [200, '<h1>Error establishing a database connection</h1>'],
            'maintenance left on' => [503, 'Briefly unavailable for scheduled maintenance.'],
        ];
    }

    public function testAPostAboutErrorsIsNotAnError(): void
    {
        self::assertTrue($this->check($this->healthy(['/' => ['code' => 200, 'body' => 'How to fix a fatal error in PHP']]))['home']);
    }

    public function testOnlyTheBeginningOfAPageIsKept(): void
    {
        self::assertSame(HealthCheck::MAX_BODY, strlen(HealthCheck::limited(str_repeat('a', HealthCheck::MAX_BODY + 5))));
    }

    public function testOnlyWhatWorkedBeforeCanRegress(): void
    {
        $before = ['home' => true, 'login' => false];

        self::assertSame([], HealthCheck::regressions($before, ['home' => true, 'login' => false]));
        self::assertSame(['home'], HealthCheck::regressions($before, ['home' => false, 'login' => false]));
        self::assertSame([], HealthCheck::regressions($before, ['home' => true, 'login' => true]));
    }

    public function testLocalAddressIsUsedForTheDomain(): void
    {
        self::assertSame('http://a.test', HealthCheck::baseUrl(['site_url' => 'http://a.test/', 'domain' => 'a.test']));
        self::assertSame('http://b.test', HealthCheck::baseUrl(['site_url' => '', 'domain' => 'b.test']));
    }

    public function testAFailedCheckSaysWhy(): void
    {
        $answers = ['http://a.test/' => ['code' => 200, 'body' => '<b>Fatal error</b>: boom'], 'http://a.test/wp-login.php' => ['code' => 0, 'body' => '']];
        $health = new HealthCheck(static fn(string $url): array => $answers[$url], '127.0.0.1', 0);
        $health->run(['site_url' => 'http://a.test', 'domain' => 'a.test']);

        self::assertSame('home (HTTP 200, the page says "<b>Fatal error</b>"), login (no answer)', $health->describe(['home', 'login']));
    }

    public function testAfterAChangeABadAnswerIsCheckedOnceMore(): void
    {
        $codes = [500, 200];
        $calls = 0;
        $health = new HealthCheck(static function (string $url) use (&$codes, &$calls): array {
            $calls++;
            return ['code' => str_ends_with($url, '/wp-login.php') ? 200 : array_shift($codes), 'body' => ''];
        }, '127.0.0.1', 0);

        self::assertSame([], $health->regressionsAfterChange(['site_url' => 'http://a.test'], ['home' => true, 'login' => true]));
        self::assertSame(4, $calls);
    }

    public function testAfterAChangeAHealthySiteIsCheckedOnce(): void
    {
        $calls = 0;
        $health = new HealthCheck(static function (string $url) use (&$calls): array {
            $calls++;
            return ['code' => 200, 'body' => ''];
        }, '127.0.0.1', 0);

        self::assertSame([], $health->regressionsAfterChange(['site_url' => 'http://a.test'], ['home' => true, 'login' => true]));
        self::assertSame(2, $calls);
    }
}
