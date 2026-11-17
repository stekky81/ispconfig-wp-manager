<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * A row of wpm_site is written by the panel and by the workers of every node. Before a worker acts on a
 * site as root, the row must describe a website of ISPConfig on this node: web_domain, which the nodes
 * cannot change, has the last word on the folder and on the user. A node that was broken into could
 * otherwise send the worker of another node to a different folder or user.
 */
final class SiteRecord
{
    /**
     * Why the row cannot be trusted, or '' when it matches its website.
     *
     * @param array<string, mixed>|null $web the web_domain row of $site['domain_id']
     * @param int $records how many wpm_site rows point to that website
     */
    public static function mismatch(array $site, ?array $web, int $serverId, int $records = 1): string
    {
        if ((int)($site['domain_id'] ?? 0) <= 0 || $web === null) {
            return 'it is not linked to a website of ISPConfig';
        }
        if ((int)$site['server_id'] !== $serverId || (int)$web['server_id'] !== $serverId) {
            return 'it belongs to another server';
        }
        foreach (['document_root', 'system_user', 'domain'] as $field) {
            if (self::normal($field, $site[$field] ?? '') !== self::normal($field, $web[$field] ?? '')) {
                return "its {$field} differs from the one of the website in ISPConfig";
            }
        }
        $subfolder = (string)($site['subfolder'] ?? '');
        if ($subfolder !== '' && !Subfolder::isValid($subfolder)) {
            return 'its subfolder is not a valid folder name';
        }
        if ($records > 1) {
            return 'more than one site in the list points to the same website and folder';
        }
        return '';
    }

    private static function normal(string $field, mixed $value): string
    {
        $value = (string)$value;
        return match ($field) {
            'document_root' => rtrim($value, '/'),
            'domain' => strtolower($value),
            default => $value,
        };
    }
}
