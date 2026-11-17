<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Update overview: read-only summary per node and per site, from the reports of the worker.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wpstation/lib/bootstrap.php';
require_once 'lib/wpstation_scope.inc.php';

$app->auth->check_module_permissions('wpstation');


$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', 'templates/wpstation_updates.htm');

$lng_file = 'lib/lang/' . $app->functions->check_language($_SESSION['s']['language']) . '_wpstation_updates.lng';
include $lng_file;
$app->tpl->setVar($wb);

$escape = static fn($value): string => $app->functions->htmlentities((string)$value);

$rows = $app->db->queryAllRecords(
    "SELECT s.wpstation_site_id, s.domain, s.subfolder, s.wp_version, s.updates_count, s.updates_json, s.last_check, s.action_status, s.action_message, s.vulns_count, s.vulns_fixable, v.server_name
     FROM wpstation_site s LEFT JOIN server v ON v.server_id = s.server_id
     WHERE s.status = 'installed' AND " . wpstation_site_scope($app, 's') . " ORDER BY s.vulns_count DESC, s.updates_count DESC, s.domain, s.subfolder"
);

$servers = [];
foreach (\WPStation\UpdateOverview::byServer($rows) as $server) {
    $servers[] = array_map($escape, $server);
}

$onlyUpdates = !empty($_GET['only_updates']);
$sites = [];
foreach ($rows as $row) {
    if ($onlyUpdates && (int)$row['updates_count'] === 0 && (int)$row['vulns_count'] === 0) {
        continue;
    }
    $updates = array_map(
        static fn(array $item): string => "{$item['name']} {$item['version']} → {$item['update']}",
        \WPStation\UpdateOverview::available($row['updates_json']),
    );
    $sites[] = [
        'id' => (int)$row['wpstation_site_id'],
        'domain' => $escape(\WPStation\Subfolder::label($row)),
        'server_name' => $escape($row['server_name']),
        'wp_version' => $escape($row['wp_version']),
        'updates_count' => (int)$row['updates_count'],
        'updates' => $escape(implode(', ', $updates)),
        'error' => $escape(trim(\WPStation\UpdateOverview::error($row['updates_json']) . ' ' . \WPStation\SiteStatus::actionProblem((string)$row['action_status'], $row['action_message']))),
        'last_check' => $escape($row['last_check'] ?? ''),
        'vulns' => (int)$row['vulns_count'] === 0 ? '' : (int)$row['vulns_count'] . ' (' . (int)$row['vulns_fixable'] . ' ' . $wb['fixable_txt'] . ')',
    ];
}

$app->tpl->setLoop('servers', $servers);
$app->tpl->setLoop('sites', $sites);
$app->tpl->setVar('only_updates', $onlyUpdates ? 1 : 0);

$app->tpl_defaults();
$app->tpl->pparse();
