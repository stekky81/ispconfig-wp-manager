<?php
// SPDX-License-Identifier: BSD-3-Clause

use WpManager\AuditLog;

/**
 * One row of the audit log for a request of the panel user. Called once the request is accepted. Rows older than
 * the retention are deleted here, at every new row: the log changes only through the panel.
 */
function wpm_audit($app, ?array $site, string $action, string $param = '', string $detail = ''): void
{
    $row = AuditLog::row(
        (string)($_SESSION['s']['user']['username'] ?? ''),
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        $site,
        $action,
        $param,
        $detail,
    );
    $app->db->query(
        'INSERT INTO wpm_audit (username, ip, wpm_site_id, domain, action, param, detail) VALUES (?, ?, ?, ?, ?, ?, ?)',
        ...array_values($row),
    );
    $app->db->query('DELETE FROM wpm_audit WHERE created < NOW() - INTERVAL ' . AuditLog::RETENTION_DAYS . ' DAY');
}

/**
 * Requests and automatic events, newest first, each request with the outcome the worker wrote for it.
 *
 * @param array $filters from AuditLog::filters()
 * @return list<array<string, string>> at most $limit rows
 */
function wpm_audit_rows($app, array $filters, int $siteId, int $limit, int $offset): array
{
    [$auditWhere, $auditValues] = AuditLog::where($filters, 'a');
    [$eventWhere, $eventValues] = AuditLog::where($filters, 'e');
    $siteOnly = $siteId > 0 ? ' AND %s.wpm_site_id = ' . $siteId : '';
    $kinds = "'" . implode("', '", AuditLog::AUTOMATIC) . "'";
    $rows = $app->db->queryAllRecords(
        "(SELECT a.created, a.username, a.ip, a.wpm_site_id, a.domain, a.action, a.param, a.detail, 'request' AS source
            FROM wpm_audit a WHERE {$auditWhere}" . sprintf($siteOnly, 'a') . ")
         UNION ALL
         (SELECT e.created, '', '', e.wpm_site_id, e.domain, e.kind, '', e.message, 'automatic'
            FROM wpm_event e WHERE e.kind IN ({$kinds}) AND {$eventWhere}" . sprintf($siteOnly, 'e') . ")
         ORDER BY created DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
        ...$auditValues,
        ...$eventValues,
    ) ?: [];
    foreach ($rows as &$row) {
        $row['outcome'] = $row['source'] === 'automatic' ? '' : '-';
        $row['outcome_message'] = '';
        $kind = $row['source'] === 'request' ? AuditLog::eventKind((string)$row['action']) : '';
        if ($kind === '') {
            continue;
        }
        $event = $app->db->queryOneRecord(
            'SELECT outcome, message FROM wpm_event WHERE wpm_site_id = ? AND kind = ? AND created >= ?
               AND created <= ? + INTERVAL ' . AuditLog::OUTCOME_HOURS . ' HOUR ORDER BY created LIMIT 1',
            (int)$row['wpm_site_id'],
            $kind,
            $row['created'],
            $row['created'],
        );
        $row['outcome'] = is_array($event) ? (string)$event['outcome'] : '…';
        $row['outcome_message'] = is_array($event) ? (string)$event['message'] : '';
    }
    unset($row);
    return $rows;
}
