<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * One-time view of the generated passwords. The caller clears the secrets right after reveal().
 * Nothing is shown while the worker may still need the record.
 */
final class SiteCredentials
{
    public function __construct(private readonly Crypto $crypto)
    {
    }

    public function canReveal(array $site): bool
    {
        return in_array($site['status'] ?? '', ['installed', 'error'], true)
            && (string)($site['secret_wp'] ?? '') !== ''
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
            'wp_password' => $this->crypto->decrypt((string)$site['secret_wp']),
            'access_type' => (string)$site['access_type'],
            'access_user' => (string)$site['access_user'],
            'access_password' => $this->crypto->decrypt((string)$site['secret_access']),
        ];
    }
}
