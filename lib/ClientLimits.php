<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * The limits of an ISPConfig client, and of its reseller, for what a new site takes. The Remote API does not check
 * them, so the panel does before it creates anything. -1 means no limit, 0 none allowed, as in ISPConfig.
 */
final class ClientLimits
{
    /** A new WordPress site with its jail takes about 400 MB. */
    public const MIN_QUOTA_MB = 500;

    /** What one new site takes: a website, a database, its user, an SFTP (shell) or FTP user. */
    public static function need(string $accessType, int $quotaMb): array
    {
        return [
            'web_domain' => 1,
            'database' => 1,
            'database_user' => 1,
            $accessType === 'ftp' ? 'ftp_user' : 'shell_user' => 1,
            'web_quota' => max(0, $quotaMb),
        ];
    }

    /**
     * What does not fit, in words for the form; [] when everything fits.
     *
     * @param array $limits the row of the client (or reseller) in ISPConfig
     * @param array<string, int> $used what it has now, same keys as need()
     * @param array<string, int> $need from need()
     */
    public static function problems(array $limits, array $used, array $need, string $who): array
    {
        $names = [
            'web_domain' => 'websites', 'database' => 'databases', 'database_user' => 'database users',
            'ftp_user' => 'FTP users', 'shell_user' => 'shell users', 'web_quota' => 'MB of web space',
        ];
        $problems = [];
        foreach ($need as $key => $amount) {
            $limit = (int)($limits['limit_' . $key] ?? -1);
            if ($limit < 0 || $amount === 0) {
                continue;
            }
            $free = $limit - (int)($used[$key] ?? 0);
            if ($free < $amount) {
                $problems[] = "{$who} has " . max(0, $free) . " {$names[$key]} left, the site needs {$amount}";
            }
        }
        if (isset($need['shell_user']) && (int)($limits['limit_shell_user'] ?? -1) !== 0
            && !in_array('jailkit', explode(',', (string)($limits['ssh_chroot'] ?? 'no,jailkit,ssh-chroot')), true)) {
            $problems[] = "{$who} may not have jailed shell users, which SFTP uses: choose FTP";
        }
        return $problems;
    }

    /**
     * The web space of a new site: the default of the settings, but never more than what is left.
     *
     * @param int $default MB, -1 = unlimited
     * @return int|null MB, -1 = unlimited; null when what is left is below MIN_QUOTA_MB
     */
    public static function quota(array $limits, int $used, int $default): ?int
    {
        $limit = (int)($limits['limit_web_quota'] ?? -1);
        if ($limit < 0) {
            return $default;
        }
        $free = $limit - $used;
        if ($free < self::MIN_QUOTA_MB) {
            return null;
        }
        return $default < 0 ? $free : min($default, $free);
    }

    /** The web servers the client may use: its list, or its default server, or any for an empty list. @return list<int> */
    public static function webServers(array $limits): array
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($limits['web_servers'] ?? ''))), static fn(int $id): bool => $id > 0));
        if ($ids === [] && (int)($limits['default_webserver'] ?? 0) > 0) {
            $ids = [(int)$limits['default_webserver']];
        }
        return $ids;
    }
}
