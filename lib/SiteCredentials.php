<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * One-time view of the generated passwords. The caller clears the secrets right after reveal().
 * Nothing is shown while the worker may still need the record. Passwords nobody looked at expire,
 * so that a compromised panel finds fewer of them.
 */
final class SiteCredentials
{
    public const EXPIRY_DAYS = 7;

    /** What the worker does with the stored passwords. */
    public const START_CLOCK = 'start';
    public const EXPIRE = 'expire';

    /**
     * @param array $site with `secret_since` as a Unix timestamp, or null while the clock has not started
     * @return string START_CLOCK, EXPIRE or '' for nothing to do
     */
    public static function housekeeping(array $site, int $now): string
    {
        if (!in_array($site['status'] ?? '', ['installed', 'error'], true) || (string)($site['secret_access'] ?? '') === '') {
            return '';
        }
        if ((string)($site['secret_since'] ?? '') === '') {
            return self::START_CLOCK;
        }
        return $now - (int)$site['secret_since'] >= self::EXPIRY_DAYS * 86400 ? self::EXPIRE : '';
    }

    /** Deleted by the worker, not shown: a reveal also clears `secret_since`. */
    public static function hasExpired(array $site): bool
    {
        return (string)($site['secret_access'] ?? '') === '' && (string)($site['secret_since'] ?? '') !== '';
    }

    public function __construct(private readonly Crypto $crypto)
    {
    }

    public function canReveal(array $site): bool
    {
        return in_array($site['status'] ?? '', ['installed', 'error'], true)
            && (string)($site['secret_access'] ?? '') !== '';
    }

    /** @return array{wp_admin_user:string, wp_password:string, access_type:string, access_user:string, access_password:string} */
    public function reveal(array $site): array
    {
        if (!$this->canReveal($site)) {
            throw new \RuntimeException('These credentials are not available');
        }
        return [
            'wp_admin_user' => (string)$site['wp_admin_user'],
            // A clone has no password of its own: it keeps the logins of the source.
            'wp_password' => (string)($site['secret_wp'] ?? '') === '' ? '' : $this->crypto->decrypt((string)$site['secret_wp']),
            'access_type' => (string)$site['access_type'],
            'access_user' => (string)$site['access_user'],
            'access_password' => $this->crypto->decrypt((string)$site['secret_access']),
        ];
    }
}
