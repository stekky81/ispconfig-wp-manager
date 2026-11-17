<?php
// SPDX-License-Identifier: BSD-3-Clause

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wpstation/lib/bootstrap.php';
require_once 'lib/wpstation_scope.inc.php';

$list_def_file = 'list/wpstation_site.list.php';

$app->auth->check_module_permissions('wpstation');

$app->load('listform_actions');

class list_action extends listform_actions
{
    public function prepareDataRow($rec)
    {
        global $app;

        // Read before the parent call, which replaces status with its display value. Short: the column is narrow,
        // and the page of the site has the whole text.
        $summary = \WPStation\SiteStatus::summary((string)$rec['status'], $rec['status_message'] ?? null, 100)
            ?: \WPStation\SiteStatus::actionProblem((string)$rec['action_status'], $rec['action_message'] ?? null, 100);
        $subfolder = (string)($rec['subfolder'] ?? '');
        $rec = parent::prepareDataRow($rec);
        if ($subfolder !== '') {
            $rec['domain'] .= '/' . $app->functions->htmlentities($subfolder);
        }
        // The core escapes only the fields of the list definition, and this text can come from WP-CLI.
        $rec['status_summary'] = $app->functions->htmlentities($summary);
        $rec['updates'] = (int)$rec['updates_count'] > 0 ? (int)$rec['updates_count'] : '';
        $rec['is_admin'] = wpstation_is_admin() ? 1 : 0;
        return $rec;
    }
}

// Sites in subfolders whose node deleted the folder: the record goes now (see wpstation_site_remove.php).
foreach ($app->db->queryAllRecords("SELECT wpstation_site_id FROM wpstation_site WHERE status = 'removed'") ?: [] as $gone) {
    $app->db->datalogDelete('wpstation_site', 'wpstation_site_id', (int)$gone['wpstation_site_id']);
}

$list = new list_action;
// Clients and resellers: the sites of the websites ISPConfig lets them read, whoever created the record.
if (!wpstation_is_admin()) {
    $list->SQLExtWhere = wpstation_site_scope($app, 'wpstation_site');
}
$app->uses('tpl');
$list->onLoad();
