<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Audit log: who asked for what in the panel, when, from which address, and how it went; with the changes
// the worker made by itself. Read-only, newest first, filtered by site, user, action and period.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
require_once 'lib/wpm_audit.inc.php';

use WpManager\AuditLog;

$app->auth->check_module_permissions('wpmanager');

if ($_SESSION['s']['user']['typ'] !== 'admin') {
    $app->error('Only administrators can read the log in this version.');
}

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', 'templates/wpm_audit.htm');

$lng_file = 'lib/lang/' . $app->functions->check_language($_SESSION['s']['language']) . '_wpm_audit.lng';
include $lng_file;
$app->tpl->setVar($wb);

$escape = static fn($value): string => $app->functions->htmlentities((string)$value);

$filters = AuditLog::filters($_GET);
// One more than a page: tells whether there is a next one.
$rows = wpm_audit_rows($app, $filters, 0, AuditLog::PAGE_SIZE + 1, $filters['page'] * AuditLog::PAGE_SIZE);
$more = count($rows) > AuditLog::PAGE_SIZE;
$rows = array_slice($rows, 0, AuditLog::PAGE_SIZE);

$lines = [];
foreach ($rows as $row) {
    $lines[] = array_map($escape, $row) + [
        'action_label' => $escape($wb['action_' . $row['action'] . '_txt'] ?? $row['action']),
        'is_automatic' => $row['source'] === 'automatic' ? 1 : 0,
    ];
}
$app->tpl->setLoop('rows', $lines);
$app->tpl->setVar('has_rows', $lines === [] ? 0 : 1);

$query = static fn(int $page): string => http_build_query(array_filter(['page' => $page] + $filters, static fn($v): bool => $v !== '' && $v !== 0));
$app->tpl->setVar('prev_query', $filters['page'] > 0 ? $escape($query($filters['page'] - 1)) : '');
$app->tpl->setVar('next_query', $more ? $escape($query($filters['page'] + 1)) : '');
foreach (['site', 'user', 'from', 'to'] as $key) {
    $app->tpl->setVar('filter_' . $key, $escape($filters[$key]));
}
$actions = '<option value="">' . $escape($wb['all_actions_txt']) . '</option>';
foreach ($wb as $key => $label) {
    if (preg_match('/^action_([a-z_]+)_txt$/', $key, $m)) {
        $selected = $filters['action'] === $m[1] ? ' selected="selected"' : '';
        $actions .= '<option value="' . $escape($m[1]) . '"' . $selected . '>' . $escape($label) . '</option>';
    }
}
$app->tpl->setVar('action_options', $actions);
$app->tpl->setVar('retention_days', AuditLog::RETENTION_DAYS);

$app->tpl_defaults();
$app->tpl->pparse();
