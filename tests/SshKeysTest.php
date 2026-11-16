<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\SiteRequest;
use WpManager\SshKeys;
use WpManager\ValidationException;

final class SshKeysTest extends TestCase
{
    private static function string(string $bytes): string
    {
        return pack('N', strlen($bytes)) . $bytes;
    }

    public static function ed25519(): string
    {
        return base64_encode(self::string('ssh-ed25519') . self::string(random_bytes(32)));
    }

    private static function rsa(int $bits): string
    {
        return base64_encode(self::string('ssh-rsa') . self::string("\x01\x00\x01") . self::string("\x00" . random_bytes(intdiv($bits, 8))));
    }

    public function testPlainKeysAreKept(): void
    {
        $ed = self::ed25519();
        $rsa = self::rsa(2048);
        $keys = SshKeys::parse("ssh-ed25519 {$ed} mario@laptop\n\n  ssh-rsa {$rsa}  \r\nssh-ed25519 {$ed} same key again\n");

        self::assertCount(2, $keys);
        self::assertSame(['type' => 'ssh-ed25519', 'blob' => $ed, 'comment' => 'same key again'], $keys[0]);
        self::assertSame("ssh-ed25519 {$ed} same key again\nssh-rsa {$rsa}", SshKeys::format($keys));
        self::assertSame([], SshKeys::parse("  \n"));
    }

    public function testTheFingerprintIsTheOneOfSshKeygen(): void
    {
        // ssh-keygen -l prints SHA256 of the decoded key, in base64 without padding.
        $blob = self::ed25519();
        self::assertSame('SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode($blob), true)), '='), SshKeys::fingerprint($blob));
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        $ed = self::ed25519();
        return [
            'options' => ["command=\"/bin/sh\" ssh-ed25519 {$ed}"],
            'from option' => ["from=\"*\",no-pty ssh-ed25519 {$ed}"],
            'unknown type' => ["ssh-dss {$ed}"],
            'type does not match the key' => ["ssh-rsa {$ed}"],
            'not base64' => ['ssh-ed25519 AAAA$$$'],
            'private key' => ['-----BEGIN OPENSSH PRIVATE KEY-----'],
            'short RSA' => ['ssh-rsa ' . self::rsa(1024)],
            'control character in the comment' => ["ssh-ed25519 {$ed} a\x07b"],
            'too many' => [implode("\n", array_map(static fn(): string => 'ssh-ed25519 ' . self::ed25519(), range(1, 11)))],
        ];
    }

    /** @dataProvider refused */
    public function testAnythingElseIsRefused(string $text): void
    {
        $this->expectException(ValidationException::class);
        SshKeys::parse($text);
    }

    public function testKeysNeedSftp(): void
    {
        $key = 'ssh-ed25519 ' . self::ed25519();
        $sftp = new SiteRequest('a.test', 1, 1, 'A', 'madmin', 'a@a.test', 'sftp', null, null, null, null, $key);
        self::assertCount(1, $sftp->sshKeys);

        $this->expectException(ValidationException::class);
        new SiteRequest('a.test', 1, 1, 'A', 'madmin', 'a@a.test', 'ftp', null, null, null, null, $key);
    }
}
