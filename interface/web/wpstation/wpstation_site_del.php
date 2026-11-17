<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Removes the site from the WordPress list ONLY. The ISPConfig website, database,
// users and files are NOT deleted: remove them from the Sites module if needed.

$list_def_file = 'list/wpstation_site.list.php';
$tform_def_file = 'form/wpstation_site.tform.php';

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';

$app->auth->check_module_permissions('wpstation');

// MVP: admins only, like the other pages of the module.
if ($_SESSION['s']['user']['typ'] !== 'admin') {
    $app->error('Only administrators can remove WordPress sites in this version.');
}

require_once '/opt/ispconfig-wpstation/lib/bootstrap.php';
require_once 'lib/wpstation_audit.inc.php';

$app->load('tform_actions');

/** Written once the row is gone: onDelete() checks the CSRF token first. */
class page_action extends tform_actions
{
    public function onAfterDelete()
    {
        global $app;
        wpstation_audit($app, $this->dataRecord, 'unlist');
    }
}

$page = new page_action;
$page->onDelete();
