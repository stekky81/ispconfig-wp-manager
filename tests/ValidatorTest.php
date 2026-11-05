<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpManager\ValidationException;
use WpManager\Validator;

final class ValidatorTest extends TestCase
{
    public function testDomainIsNormalised(): void
    {
        self::assertSame('example.com', Validator::domain('  Example.COM '));
        self::assertSame('xn--caff-8oa.it', Validator::domain('xn--caff-8oa.it'));
    }

    public static function badDomains(): array
    {
        return [
            'no dot' => ['localhost'],
            'shell injection' => ['example.com; rm -rf /'],
            'path traversal' => ['../../etc'],
            'leading dash' => ['-example.com'],
            'space' => ['exa mple.com'],
            'empty label' => ['example..com'],
            'unicode not punycoded' => ['caffè.it'],
            'too long label' => [str_repeat('a', 64) . '.com'],
        ];
    }

    #[DataProvider('badDomains')]
    public function testDomainRejectsUnsafeValues(string $value): void
    {
        $this->expectException(ValidationException::class);
        Validator::domain($value);
    }

    public function testWpUserRejectsAdminAndShellCharacters(): void
    {
        self::assertSame('stefano', Validator::wpUser('stefano'));
        foreach (['admin', 'Admin', 'a b', 'x$(id)', 'ab', str_repeat('a', 61)] as $bad) {
            try {
                Validator::wpUser($bad);
                self::fail("Accepted: {$bad}");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSystemUserMustBeAnIspconfigWebUser(): void
    {
        self::assertSame('web12', Validator::systemUser('web12'));
        foreach (['root', 'web', 'web1;id', 'ispconfig', 'web12 ', 'www-data'] as $bad) {
            try {
                Validator::systemUser($bad);
                self::fail("Accepted: {$bad}");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPositiveId(): void
    {
        self::assertSame(5, Validator::positiveId('5'));
        self::assertSame(5, Validator::positiveId(5));
        foreach (['0', '-1', '1.5', 'abc', '', '05', null] as $bad) {
            try {
                Validator::positiveId($bad);
                self::fail('Accepted: ' . var_export($bad, true));
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // Regression: two long domains with the same beginning got the same database and user names,
    // so the second site could not be created.
    public function testLongDomainsGetDifferentNames(): void
    {
        $one = Validator::dbNameFromDomain('questo-dominio-e-lunghissimo-primo.ispc.test');
        $two = Validator::dbNameFromDomain('questo-dominio-e-lunghissimo-secondo.ispc.test');

        self::assertNotSame($one, $two);
        self::assertLessThanOrEqual(24, strlen($one));
        self::assertSame($one, Validator::dbNameFromDomain('questo-dominio-e-lunghissimo-primo.ispc.test'));
        self::assertStringStartsWith('questo_dominio_e_', $one);
    }

    public function testShortDomainsKeepAReadableName(): void
    {
        self::assertSame('sito1_ispc_test', Validator::dbNameFromDomain('sito1.ispc.test'));
    }

    public function testDbNameFromDomain(): void
    {
        self::assertSame('my_shop_example_com', Validator::dbNameFromDomain('my-shop.example.com'));
        self::assertLessThanOrEqual(24, strlen(Validator::dbNameFromDomain(str_repeat('a', 60) . '.com')));
    }
}
