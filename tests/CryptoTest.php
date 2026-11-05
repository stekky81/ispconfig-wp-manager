<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Crypto;

final class CryptoTest extends TestCase
{
    private function crypto(): Crypto
    {
        return new Crypto(base64_decode(Crypto::generateKey()));
    }

    public function testRoundTrip(): void
    {
        $c = $this->crypto();
        $secret = "p@ss w0rd'\"\n;$(id)";
        self::assertSame($secret, $c->decrypt($c->encrypt($secret)));
    }

    public function testCiphertextDoesNotContainPlaintextAndIsRandomised(): void
    {
        $c = $this->crypto();
        $a = $c->encrypt('supersecret');
        $b = $c->encrypt('supersecret');
        self::assertStringNotContainsString('supersecret', $a);
        self::assertNotSame($a, $b);
    }

    public function testWrongKeyFails(): void
    {
        $token = $this->crypto()->encrypt('x');
        $this->expectException(\RuntimeException::class);
        $this->crypto()->decrypt($token);
    }

    public function testTamperedTokenFails(): void
    {
        $c = $this->crypto();
        $token = $c->encrypt('x');
        $tampered = substr($token, 0, -2) . (str_ends_with($token, 'A=') ? 'B=' : 'A=');
        $this->expectException(\RuntimeException::class);
        $c->decrypt($tampered);
    }

    public function testKeyFileMustBePrivate(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpmkey');
        file_put_contents($file, Crypto::generateKey());
        chmod($file, 0o644);
        try {
            Crypto::fromFile($file);
            self::fail('World-readable key accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('must not be readable', $e->getMessage());
        }
        chmod($file, 0o600);
        self::assertInstanceOf(Crypto::class, Crypto::fromFile($file));
        unlink($file);
    }

    public function testRandomPassword(): void
    {
        $p = Crypto::randomPassword(32);
        self::assertSame(32, strlen($p));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $p);
        self::assertNotSame($p, Crypto::randomPassword(32));
    }
}
