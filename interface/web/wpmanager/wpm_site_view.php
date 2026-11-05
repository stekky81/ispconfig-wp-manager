<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Site detail page: status, install log, links and the one-time view of the generated
// passwords. Revealing them clears them from wpm_site, so they can be seen only once.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';

$app->auth->check_module_permissions('wpmanager');

// MVP: admins only, like the creation form.
if ($_SESSION['s']['user']['typ'] !== 'admin') {
    $app->error('Only administrators can view WordPress sites in this version.');
}

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', 'templates/wpm_site_view.htm');

$lng_file = 'lib/lang/' . $app->functions->check_language($_SESSION['s']['language']) . '_wpm_site_view.lng';
include $lng_file;
$app->tpl->setVar($wb);

$id = $app->functions->intval($_REQUEST['id'] ?? 0);
$site = $app->db->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', $id);
if (!is_array($site)) {
    $app->error($wb['site_not_found_txt']);
}

$escape = static fn($value): string => $app->functions->htmlentities((string)$value);

$config = \WpManager\Config::load();
$credentials = new \WpManager\SiteCredentials(\WpManager\Crypto::fromFile($config->get('key_file')));

if (isset($_POST['reveal'])) {
    $app->auth->csrf_token_check();
    if ($credentials->canReveal($site)) {
        $shown = $credentials->reveal($site);
        // Clear first: if this fails the passwords stay stored and nothing is shown.
        $app->db->datalogUpdate('wpm_site', ['secret_wp' => null, 'secret_access' => null], 'wpm_site_id', $id);
        $site['secret_wp'] = $site['secret_access'] = null;
        $app->tpl->setVar('revealed', 1);
        $app->tpl->setVar(array_map($escape, $shown));
    }
}

foreach (['domain', 'status', 'origin', 'access_type', 'access_user', 'wp_admin_user', 'wp_version', 'db_name', 'db_user', 'document_root', 'system_user'] as $field) {
    $app->tpl->setVar($field, $escape($site[$field]));
}
$app->tpl->setVar('id', $id);
$app->tpl->setVar('status_message', $escape($site['status_message'] ?? ''));
$app->tpl->setVar('can_reveal', $credentials->canReveal($site) ? 1 : 0);
$app->tpl->setVar('is_installed', $site['status'] === 'installed' ? 1 : 0);
$app->tpl->setVar('website_redirect', rawurlencode('sites/web_vhost_domain_edit.php?id=' . (int)$site['domain_id'] . '&type=domain'));

$csrf_token = $app->auth->csrf_token_get('wpm_site_view');
$app->tpl->setVar('_csrf_id', $csrf_token['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrf_token['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
