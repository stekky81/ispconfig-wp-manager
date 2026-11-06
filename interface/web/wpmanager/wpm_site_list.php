<?php
// SPDX-License-Identifier: BSD-3-Clause

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';

$list_def_file = 'list/wpm_site.list.php';

$app->auth->check_module_permissions('wpmanager');

// MVP: admins only, like the other pages of the module.
if ($_SESSION['s']['user']['typ'] !== 'admin') {
    $app->error('Only administrators can view the WordPress sites in this version.');
}

$app->load('listform_actions');

class list_action extends listform_actions
{
    public function prepareDataRow($rec)
    {
        global $app;

        // Read before the parent call, which replaces status with its display value.
        $summary = \WpManager\SiteStatus::summary((string)$rec['status'], $rec['status_message'] ?? null)
            ?: \WpManager\SiteStatus::actionProblem((string)$rec['action_status'], $rec['action_message'] ?? null);
        $rec = parent::prepareDataRow($rec);
        // The core escapes only the fields of the list definition, and this text can come from WP-CLI.
        $rec['status_summary'] = $app->functions->htmlentities($summary);
        $rec['updates'] = (int)$rec['updates_count'] > 0 ? (int)$rec['updates_count'] : '';
        return $rec;
    }
}

$list = new list_action;
$list->onLoad();
