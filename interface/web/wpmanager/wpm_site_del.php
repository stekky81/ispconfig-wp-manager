<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Removes the site from the WordPress list ONLY. The ISPConfig website, database,
// users and files are NOT deleted: remove them from the Sites module if needed.

$list_def_file = 'list/wpm_site.list.php';
$tform_def_file = 'form/wpm_site.tform.php';

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';

$app->auth->check_module_permissions('wpmanager');

$app->uses('tform_actions');
$app->tform_actions->onDelete();
