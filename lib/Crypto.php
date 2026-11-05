<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Encrypts job secrets (DB password, WP admin password) before they enter sys_datalog,
 * which ISPConfig keeps in plain text and replicates to the nodes.
 * Uses libsodium secretbox with a key shared by the master and the nodes.
 */
final class Crypto
{
    private const PREFIX = 'wpm1:';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid key length');
        }
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Key file not found: {$path}");
        }
        clearstatcache(true, $path);
        $perms = fileperms($path) & 0o077;
        if ($perms !== 0) {
            throw new \RuntimeException("Key file {$path} must not be readable by group or others");
        }
        $raw = base64_decode(trim((string)file_get_contents($path)), true);
        if ($raw === false) {
            throw new \RuntimeException('Key file is not valid base64');
        }
        return new self($raw);
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $token): string
    {
        if (!str_starts_with($token, self::PREFIX)) {
            throw new \RuntimeException('Unknown secret format');
        }
        $raw = base64_decode(substr($token, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Corrupted secret');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Secret cannot be decrypted (wrong key?)');
        }
        return $plain;
    }

    public static function randomPassword(int $length = 24): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_.';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
