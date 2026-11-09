<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Crypto;
use WpManager\SiteCredentials;

final class SiteCredentialsTest extends TestCase
{
    private Crypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new Crypto(base64_decode(Crypto::generateKey()));
    }

    private function site(array $over = []): array
    {
        return $over + [
            'status' => 'installed',
            'wp_admin_user' => 'stefano',
            'access_type' => 'ftp',
            'access_user' => 'c1_shopf',
            'secret_wp' => $this->crypto->encrypt('WpPw456'),
            'secret_access' => $this->crypto->encrypt('AccessPw789'),
        ];
    }

    public function testRevealDecryptsBothPasswords(): void
    {
        self::assertSame([
            'wp_admin_user' => 'stefano',
            'wp_password' => 'WpPw456',
            'access_type' => 'ftp',
            'access_user' => 'c1_shopf',
            'access_password' => 'AccessPw789',
        ], (new SiteCredentials($this->crypto))->reveal($this->site()));
    }

    // A clone keeps the logins of its source: only the file access password is new.
    public function testAClonedSiteRevealsOnlyTheAccessPassword(): void
    {
        $credentials = new SiteCredentials($this->crypto);
        $site = $this->site(['secret_wp' => null, 'wp_admin_user' => '']);

        self::assertTrue($credentials->canReveal($site));
        self::assertSame('', $credentials->reveal($site)['wp_password']);
        self::assertSame('AccessPw789', $credentials->reveal($site)['access_password']);
    }

    public function testAvailableOnceTheWorkerIsDone(): void
    {
        $credentials = new SiteCredentials($this->crypto);

        self::assertTrue($credentials->canReveal($this->site()));
        self::assertTrue($credentials->canReveal($this->site(['status' => 'error'])));
        self::assertFalse($credentials->canReveal($this->site(['status' => 'queued'])));
        self::assertFalse($credentials->canReveal($this->site(['status' => 'installing'])));
    }

    public function testNotAvailableAfterTheSecretsWereCleared(): void
    {
        $credentials = new SiteCredentials($this->crypto);

        self::assertFalse($credentials->canReveal($this->site(['secret_access' => ''])));
    }

    public function testRevealRefusesWhatCanNotBeRevealed(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SiteCredentials($this->crypto))->reveal($this->site(['status' => 'queued']));
    }

    public function testTheClockStartsWhenTheSiteIsReady(): void
    {
        $now = 1_800_000_000;

        self::assertSame(SiteCredentials::START_CLOCK, SiteCredentials::housekeeping($this->site(['secret_since' => null]), $now));
        self::assertSame(SiteCredentials::START_CLOCK, SiteCredentials::housekeeping($this->site(['status' => 'error']), $now));
        self::assertSame('', SiteCredentials::housekeeping($this->site(['status' => 'queued']), $now));
        self::assertSame('', SiteCredentials::housekeeping($this->site(['status' => 'installing']), $now));
    }

    public function testPasswordsNobodyLookedAtExpireAfterAWeek(): void
    {
        $now = 1_800_000_000;
        $week = SiteCredentials::EXPIRY_DAYS * 86400;

        self::assertSame(7, SiteCredentials::EXPIRY_DAYS);
        self::assertSame('', SiteCredentials::housekeeping($this->site(['secret_since' => (string)($now - $week + 60)]), $now));
        self::assertSame(SiteCredentials::EXPIRE, SiteCredentials::housekeeping($this->site(['secret_since' => (string)($now - $week)]), $now));
        self::assertSame(SiteCredentials::EXPIRE, SiteCredentials::housekeeping($this->site(['secret_since' => $now - 30 * 86400]), $now));
    }

    public function testNothingToDoOnceThePasswordsAreGone(): void
    {
        $now = 1_800_000_000;

        self::assertSame('', SiteCredentials::housekeeping($this->site(['secret_access' => null, 'secret_since' => $now - 30 * 86400]), $now));
        // Detected sites never had any.
        self::assertSame('', SiteCredentials::housekeeping(['status' => 'installed', 'secret_access' => null, 'secret_since' => null], $now));
    }

    // A reveal clears secret_since too: the page then says "already shown", not "expired".
    public function testExpiredIsToldApartFromAlreadyShown(): void
    {
        self::assertTrue(SiteCredentials::hasExpired($this->site(['secret_wp' => null, 'secret_access' => null, 'secret_since' => '2026-11-01 10:00:00'])));
        self::assertFalse(SiteCredentials::hasExpired($this->site(['secret_wp' => null, 'secret_access' => null, 'secret_since' => null])));
        self::assertFalse(SiteCredentials::hasExpired($this->site(['secret_since' => '2026-11-01 10:00:00'])));
        self::assertFalse(SiteCredentials::hasExpired(['status' => 'installed']));
    }
}
