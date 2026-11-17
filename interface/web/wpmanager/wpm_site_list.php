<?php
// SPDX-License-Identifier: BSD-3-Clause

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
require_once 'lib/wpm_scope.inc.php';

$list_def_file = 'list/wpm_site.list.php';

$app->auth->check_module_permissions('wpmanager');

$app->load('listform_actions');

class list_action extends listform_actions
{
    public function prepareDataRow($rec)
    {
        global $app;

        // Read before the parent call, which replaces status with its display value.
        $summary = \WpManager\SiteStatus::summary((string)$rec['status'], $rec['status_message'] ?? null)
            ?: \WpManager\SiteStatus::actionProblem((string)$rec['action_status'], $rec['action_message'] ?? null);
        $subfolder = (string)($rec['subfolder'] ?? '');
        $rec = parent::prepareDataRow($rec);
        if ($subfolder !== '') {
            $rec['domain'] .= '/' . $app->functions->htmlentities($subfolder);
        }
        // The core escapes only the fields of the list definition, and this text can come from WP-CLI.
        $rec['status_summary'] = $app->functions->htmlentities($summary);
        $rec['updates'] = (int)$rec['updates_count'] > 0 ? (int)$rec['updates_count'] : '';
        $rec['is_admin'] = wpm_is_admin() ? 1 : 0;
        return $rec;
    }
}

// Sites in subfolders whose node deleted the folder: the record goes now (see wpm_site_remove.php).
foreach ($app->db->queryAllRecords("SELECT wpm_site_id FROM wpm_site WHERE status = 'removed'") ?: [] as $gone) {
    $app->db->datalogDelete('wpm_site', 'wpm_site_id', (int)$gone['wpm_site_id']);
}

$list = new list_action;
// Clients and resellers: the sites of the websites ISPConfig lets them read, whoever created the record.
if (!wpm_is_admin()) {
    $list->SQLExtWhere = wpm_site_scope($app, 'wpm_site');
}
$app->uses('tpl');
$list->onLoad();
