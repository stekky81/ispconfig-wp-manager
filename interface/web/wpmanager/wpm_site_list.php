<?php
// SPDX-License-Identifier: BSD-3-Clause

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';

$list_def_file = 'list/wpm_site.list.php';

$app->auth->check_module_permissions('wpmanager');

$app->uses('listform_actions');
$app->listform_actions->onLoad();
