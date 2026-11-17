<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * Who asked for what in the panel, and when: every request that changes something, and every view of a password.
 * The panel writes `wpstation_audit`; the worker writes the outcome in `wpstation_event`, and the page of the log shows the two
 * together. Changes the worker makes by itself (automatic updates, the end of a debug mode or of a temporary login)
 * are events of AUTOMATIC kinds, shown in the log as done by nobody. Never a password, in any row.
 */
final class AuditLog
{
    /** Requests and events older than this are deleted. */
    public const RETENTION_DAYS = 365;
    public const PAGE_SIZE = 50;

    /** Events that no request of the panel started. */
    public const AUTOMATIC = ['auto_update', 'debug_expired', 'login_expired'];

    /** The outcome of a request is the first event of its kind on its site within this time. */
    public const OUTCOME_HOURS = 24;

    /**
     * The row to insert. What a user typed or a site holds is cut and stripped of control characters.
     *
     * @return array{username:string, ip:string, wpstation_site_id:int, domain:string, action:string, param:string, detail:string}
     */
    public static function row(string $username, string $ip, ?array $site, string $action, string $param = '', string $detail = ''): array
    {
        return [
            'username' => self::clean($username, 64),
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '',
            'wpstation_site_id' => (int)($site['wpstation_site_id'] ?? 0),
            'domain' => self::clean(Subfolder::label($site ?? []), 255),
            'action' => self::clean($action, 32),
            'param' => self::clean($param, 255),
            'detail' => self::clean($detail, 1000),
        ];
    }

    /** The kind of the event the worker writes for this request; '' when the panel does it all itself. */
    public static function eventKind(string $action): string
    {
        return match ($action) {
            'create' => 'install',
            'clone', 'staging' => 'clone',
            'backup', 'restore', 'update', 'wpcron', 'refresh', 'push', 'maintenance', 'debug', 'debuglog',
            'login', 'password', 'plugin_activate', 'plugin_deactivate', 'plugin_delete', 'theme_activate',
            'theme_delete', 'harden', 'fix_permissions' => $action,
            default => '',
        };
    }

    /**
     * The filters of the log page, from the query string: only what the SQL of the page can use.
     *
     * @return array{site:string, user:string, action:string, from:string, to:string, page:int}
     */
    public static function filters(array $query): array
    {
        $text = static fn(string $key, int $max): string => is_string($query[$key] ?? null) ? self::clean(trim($query[$key]), $max) : '';
        $date = static function (string $key) use ($query): string {
            $value = is_string($query[$key] ?? null) ? trim($query[$key]) : '';
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : '';
        };
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int)$query['page'] : 0;
        return [
            'site' => $text('site', 255),
            'user' => $text('user', 64),
            'action' => preg_match('/^[a-z_]{1,32}$/', is_string($query['action'] ?? null) ? $query['action'] : '') === 1 ? $query['action'] : '',
            'from' => $date('from'),
            'to' => $date('to'),
            'page' => min($page, 10000),
        ];
    }

    /**
     * WHERE of the log, for requests (`a`) or automatic events (`e`), with its values in order.
     *
     * @return array{0:string, 1:list<string>}
     */
    public static function where(array $filters, string $table): array
    {
        $isAudit = $table === 'a';
        $conditions = [];
        $values = [];
        if ($filters['site'] !== '') {
            $conditions[] = "{$table}.domain LIKE ?";
            $values[] = '%' . addcslashes($filters['site'], '%_\\') . '%';
        }
        if ($filters['user'] !== '') {
            // Automatic events have no user: a search for one leaves them out.
            $conditions[] = $isAudit ? 'a.username = ?' : '1 = 0';
            if ($isAudit) {
                $values[] = $filters['user'];
            }
        }
        if ($filters['action'] !== '') {
            $conditions[] = $isAudit ? 'a.action = ?' : 'e.kind = ?';
            $values[] = $filters['action'];
        }
        if ($filters['from'] !== '') {
            $conditions[] = "{$table}.created >= ?";
            $values[] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            $conditions[] = "{$table}.created <= ?";
            $values[] = $filters['to'] . ' 23:59:59';
        }
        return [$conditions === [] ? '1 = 1' : implode(' AND ', $conditions), $values];
    }

    private static function clean(string $text, int $max): string
    {
        return mb_substr((string)preg_replace('/\p{C}+/u', ' ', mb_scrub($text, 'UTF-8')), 0, $max);
    }
}
