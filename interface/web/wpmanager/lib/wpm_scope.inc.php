<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Who sees what. Admins see every record of the module. Clients and resellers see a site only through its website in
// ISPConfig, with the permissions ISPConfig gives them on it (getAuthSQL: r to see, u to act, d to delete): the record
// of the module never decides alone, so a website moved to another client in ISPConfig moves in the module too.

use WpManager\ClientLimits;

function wpm_is_admin(): bool
{
    return ($_SESSION['s']['user']['typ'] ?? '') === 'admin';
}

function wpm_admin_only($app): void
{
    if (!wpm_is_admin()) {
        $app->error('Only administrators can open this page.');
    }
}

/** The site, if this user has the permission on its website; null otherwise. */
function wpm_site($app, int $id, string $perm = 'r'): ?array
{
    if (wpm_is_admin()) {
        $site = $app->db->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', $id);
        return is_array($site) ? $site : null;
    }
    if (!in_array($perm, ['r', 'u', 'd'], true)) {
        return null;
    }
    $app->uses('tform');
    $site = $app->db->queryOneRecord(
        "SELECT s.* FROM wpm_site s INNER JOIN web_domain d ON d.domain_id = s.domain_id AND d.domain = s.domain AND d.type = 'vhost'
         WHERE s.wpm_site_id = ? AND " . $app->tform->getAuthSQL($perm, 'd'),
        $id,
    );
    return is_array($site) ? $site : null;
}

/** A condition on the records of the module (alias $alias) for lists. */
function wpm_site_scope($app, string $alias = 's'): string
{
    if (wpm_is_admin()) {
        return '1 = 1';
    }
    $app->uses('tform');
    return "{$alias}.domain_id IN (SELECT d.domain_id FROM web_domain d WHERE d.type = 'vhost' AND d.domain = {$alias}.domain AND "
        . $app->tform->getAuthSQL('r', 'd') . ')';
}

/** The clients this user may create sites for: itself, and its clients for a reseller. Admins: null, any client. */
function wpm_allowed_clients($app): ?array
{
    if (wpm_is_admin()) {
        return null;
    }
    $own = (int)($_SESSION['s']['user']['client_id'] ?? 0);
    if ($own <= 0) {
        return [];
    }
    $ids = [$own];
    foreach ($app->db->queryAllRecords('SELECT client_id FROM client WHERE parent_client_id = ?', $own) ?: [] as $row) {
        $ids[] = (int)$row['client_id'];
    }
    return $ids;
}

/** What a client, or a reseller with all its clients, has now. */
function wpm_usage($app, array $clientIds): array
{
    $groups = [];
    foreach ($clientIds as $clientId) {
        $group = $app->db->queryOneRecord('SELECT groupid FROM sys_group WHERE client_id = ?', $clientId);
        if (is_array($group)) {
            $groups[] = (int)$group['groupid'];
        }
    }
    if ($groups === []) {
        return [];
    }
    $in = implode(',', $groups);
    $count = static fn(string $sql): int => (int)($app->db->queryOneRecord($sql)['n'] ?? 0);
    return [
        'web_domain' => $count("SELECT COUNT(*) AS n FROM web_domain WHERE type = 'vhost' AND sys_groupid IN ({$in})"),
        'database' => $count("SELECT COUNT(*) AS n FROM web_database WHERE sys_groupid IN ({$in})"),
        'database_user' => $count("SELECT COUNT(*) AS n FROM web_database_user WHERE sys_groupid IN ({$in})"),
        'ftp_user' => $count("SELECT COUNT(*) AS n FROM ftp_user WHERE sys_groupid IN ({$in})"),
        'shell_user' => $count("SELECT COUNT(*) AS n FROM shell_user WHERE sys_groupid IN ({$in})"),
        'web_quota' => $count("SELECT COALESCE(SUM(hd_quota), 0) AS n FROM web_domain WHERE type = 'vhost' AND hd_quota > 0 AND sys_groupid IN ({$in})"),
    ];
}

/**
 * The client, and its reseller if it has one, with what each has now: the limits of both apply.
 *
 * @return list<array{who:string, limits:array, used:array}>
 */
function wpm_limit_holders($app, int $clientId): array
{
    $client = $app->db->queryOneRecord('SELECT * FROM client WHERE client_id = ?', $clientId);
    if (!is_array($client)) {
        return [];
    }
    $holders = [['who' => 'The client', 'limits' => $client, 'used' => wpm_usage($app, [$clientId])]];
    if ((int)$client['parent_client_id'] > 0) {
        $reseller = $app->db->queryOneRecord('SELECT * FROM client WHERE client_id = ?', $client['parent_client_id']);
        if (is_array($reseller)) {
            $family = [(int)$reseller['client_id']];
            foreach ($app->db->queryAllRecords('SELECT client_id FROM client WHERE parent_client_id = ?', $reseller['client_id']) ?: [] as $row) {
                $family[] = (int)$row['client_id'];
            }
            $holders[] = ['who' => 'The reseller', 'limits' => $reseller, 'used' => wpm_usage($app, $family)];
        }
    }
    return $holders;
}

/**
 * The web space for a new site of this client, and what does not fit. Admins: the default, no limit checked, as in
 * ISPConfig.
 *
 * @return array{quota:int, problems:list<string>}
 */
function wpm_limits_for_new_site($app, int $clientId, string $accessType, int $defaultQuota): array
{
    if (wpm_is_admin()) {
        return ['quota' => $defaultQuota, 'problems' => []];
    }
    $holders = wpm_limit_holders($app, $clientId);
    if ($holders === []) {
        return ['quota' => $defaultQuota, 'problems' => ['Unknown client']];
    }
    $quota = $defaultQuota;
    $problems = [];
    foreach ($holders as $holder) {
        $fit = ClientLimits::quota($holder['limits'], (int)($holder['used']['web_quota'] ?? 0), $quota);
        if ($fit === null) {
            $problems[] = "{$holder['who']} has less than " . ClientLimits::MIN_QUOTA_MB . ' MB of web space left';
        } else {
            $quota = $fit;
        }
    }
    foreach ($holders as $holder) {
        $problems = array_merge($problems, ClientLimits::problems($holder['limits'], $holder['used'], ClientLimits::need($accessType, $quota), $holder['who']));
    }
    return ['quota' => $quota, 'problems' => array_values(array_unique($problems))];
}

/** The owner of a website in ISPConfig, for the record of the module: the same user and group. */
function wpm_owner($app, int $domainId): array
{
    $web = $app->db->queryOneRecord('SELECT sys_userid, sys_groupid FROM web_domain WHERE domain_id = ?', $domainId);
    return is_array($web)
        ? ['sys_userid' => (int)$web['sys_userid'], 'sys_groupid' => (int)$web['sys_groupid']]
        : ['sys_userid' => (int)$_SESSION['s']['user']['userid'], 'sys_groupid' => (int)$_SESSION['s']['user']['default_group']];
}
