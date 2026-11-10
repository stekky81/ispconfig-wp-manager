<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Confirms the two actions of a staging site: update it from production (direction=refresh) or
// push it to production (direction=push). Both replace everything on one side, so the admin types
// the domain that will be overwritten. The worker of the node does the copy.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
require_once 'lib/wpm_config.inc.php';

$app->auth->check_module_permissions('wpmanager');

if ($_SESSION['s']['user']['typ'] !== 'admin') {
    $app->error('Only administrators can manage staging sites in this version.');
}

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', 'templates/wpm_site_sync.htm');

$lng_file = 'lib/lang/' . $app->functions->check_language($_SESSION['s']['language']) . '_wpm_site_sync.lng';
include $lng_file;
$app->tpl->setVar($wb);

$escape = static fn($value): string => $app->functions->htmlentities((string)$value);

$id = $app->functions->intval($_REQUEST['id'] ?? 0);
$direction = (string)($_REQUEST['direction'] ?? '');
if (!in_array($direction, ['refresh', 'push'], true)) {
    $app->error($wb['invalid_txt']);
}
$staging = $app->db->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ? AND staging_of > 0', $id);
$production = is_array($staging) ? $app->db->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', $staging['staging_of']) : null;
try {
    if (!is_array($staging) || !is_array($production)) {
        throw new \RuntimeException($wb['invalid_txt']);
    }
    \WpManager\SiteSync::assertPair($staging, $production);
} catch (\RuntimeException $e) {
    $app->error($escape($e->getMessage()));
}
$target = $direction === 'push' ? $production : $staging;

if (isset($_POST['confirm_domain'])) {
    $app->auth->csrf_token_check();
    if (trim((string)$_POST['confirm_domain']) !== (string)$target['domain']) {
        $app->tpl->setVar('error_msg', $escape($wb['confirm_error_txt']));
    } elseif ($staging['action_request'] !== '' || $production['action_request'] !== '') {
        $app->tpl->setVar('error_msg', $escape($wb['busy_txt']));
    } else {
        // Requested on the staging record: the worker reads both sites itself.
        $app->db->datalogUpdate('wpm_site', [
            'action_request' => $direction,
            'action_param' => '',
            'action_status' => 'requested',
            'action_message' => '',
        ], 'wpm_site_id', $id);
        header('Location: wpm_site_view.php?id=' . $id);
        exit;
    }
}

$lost = [];
if ($direction === 'push') {
    $lost = \WpManager\SiteStaging::lostOnPush(
        json_decode((string)$production['updates_json'], true) ?: [],
        json_decode((string)$staging['updates_json'], true) ?: [],
    );
}

$app->tpl->setVar('id', $id);
$app->tpl->setVar('direction', $direction);
$app->tpl->setVar('is_push', $direction === 'push' ? 1 : 0);
$app->tpl->setVar('staging_domain', $escape($staging['domain']));
$app->tpl->setVar('production_domain', $escape($production['domain']));
$app->tpl->setVar('target_domain', $escape($target['domain']));
$app->tpl->setLoop('lost', array_map(static fn(string $line): array => ['line' => $escape($line)], $lost));
$app->tpl->setVar('has_lost', $lost === [] ? 0 : 1);
$app->tpl->setVar('checked_at', $escape(($production['last_check'] ?? '') . ' / ' . ($staging['last_check'] ?? '')));

$csrf_token = $app->auth->csrf_token_get('wpm_site_sync');
$app->tpl->setVar('_csrf_id', $csrf_token['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrf_token['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
