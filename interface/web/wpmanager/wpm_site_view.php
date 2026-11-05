<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Site detail page: status, install log, links and the one-time view of the generated
// passwords. Revealing them clears them from wpm_site, so they can be seen only once.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
require_once 'lib/wpm_config.inc.php';

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

$config = wpm_config($app);
$credentials = new \WpManager\SiteCredentials(\WpManager\Crypto::fromFile($config->get('key_file')));

// Links, not the page form: the form may also carry the reveal field.
if (isset($_GET['check'])) {
    $app->auth->csrf_token_check('GET');
    $app->db->query('UPDATE wpm_site SET last_check = NULL WHERE wpm_site_id = ?', $id);
    $site['last_check'] = null;
    $app->tpl->setVar('check_requested', 1);
}

// Read directly from the master by the worker: nothing to hand over to the node.
if (isset($_GET['auto']) && in_array($_GET['auto'], ['y', 'n'], true)) {
    $app->auth->csrf_token_check('GET');
    $app->db->query("UPDATE wpm_site SET auto_update = ?, auto_skip = '' WHERE wpm_site_id = ?", $_GET['auto'], $id);
    $site['auto_update'] = $_GET['auto'];
}

if (isset($_GET['backup_uploads']) && in_array($_GET['backup_uploads'], ['y', 'n'], true)) {
    $app->auth->csrf_token_check('GET');
    $app->db->query('UPDATE wpm_site SET backup_uploads = ? WHERE wpm_site_id = ?', $_GET['backup_uploads'], $id);
    $site['backup_uploads'] = $_GET['backup_uploads'];
}

// The request reaches the node through the datalog, like every other change.
$action = (string)($_GET['action'] ?? '');
if (in_array($action, ['backup', 'restore', 'update', 'wpcron'], true)) {
    $app->auth->csrf_token_check('GET');
    $param = match ($action) {
        'restore' => (string)($_GET['backup'] ?? ''),
        'update' => (string)($_GET['scope'] ?? ''),
        'wpcron' => (string)($_GET['mode'] ?? ''),
        default => '',
    };
    $allowed = match ($action) {
        'restore' => array_column(json_decode((string)$site['backups_json'], true) ?: [], 'id'),
        'update' => \WpManager\SiteUpdater::SCOPES,
        'wpcron' => [\WpManager\WpCron::WORDPRESS, \WpManager\WpCron::REAL],
        default => [''],
    };
    $valid = $site['status'] === 'installed' && in_array($param, $allowed, true);
    if ($valid && $site['action_request'] === '') {
        $update = ['action_request' => $action, 'action_param' => $param, 'action_status' => 'requested', 'action_message' => ''];
        try {
            if ($action === 'wpcron' && !$config->isDryRun()) {
                // Job first when turning WP-Cron off, so that the site is not left with no cron at all.
                $api = \WpManager\IspconfigApi::fromConfig($config);
                if ($param === \WpManager\WpCron::REAL && (int)$site['cron_job_id'] === 0) {
                    $jobId = (int)$api->call('sites_cron_add', ['client_id' => (int)$site['client_id'], 'params' => \WpManager\WpCron::jobParams($site)]);
                    $app->db->query('UPDATE wpm_site SET cron_job_id = ? WHERE wpm_site_id = ?', $jobId, $id);
                } elseif ($param === \WpManager\WpCron::WORDPRESS && (int)$site['cron_job_id'] > 0) {
                    $api->call('sites_cron_delete', ['cron_id' => (int)$site['cron_job_id']]);
                    $app->db->query('UPDATE wpm_site SET cron_job_id = 0 WHERE wpm_site_id = ?', $id);
                }
                $api->logout();
            }
            $app->db->datalogUpdate('wpm_site', $update, 'wpm_site_id', $id);
            $site = $update + $site;
        } catch (\Throwable $e) {
            $site['action_status'] = 'failed';
            $site['action_message'] = $e->getMessage();
        }
    }
}

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
$app->tpl->setVar('site_url', $escape($site['site_url'] ?: 'http://' . $site['domain']));
$app->tpl->setVar('status_message', $escape($site['status_message'] ?? ''));
$app->tpl->setVar('can_reveal', $credentials->canReveal($site) ? 1 : 0);
$app->tpl->setVar('is_installed', $site['status'] === 'installed' ? 1 : 0);
$app->tpl->setVar('website_redirect', rawurlencode('sites/web_vhost_domain_edit.php?id=' . (int)$site['domain_id'] . '&type=domain'));

$updates = array_map(
    static fn(array $item): array => array_map($escape, $item),
    \WpManager\UpdateOverview::available($site['updates_json']),
);
$app->tpl->setLoop('updates', $updates);
$app->tpl->setVar('has_updates', $updates === [] ? 0 : 1);
$major = \WpManager\AutoUpdatePolicy::majorUpdate(json_decode((string)$site['updates_json'], true) ?: []);
$app->tpl->setVar('major_update', $escape($major));
$app->tpl->setVar('update_error', $escape(\WpManager\UpdateOverview::error($site['updates_json'])));
$app->tpl->setVar('last_check', $escape($site['last_check'] ?? ''));

$action_busy = $site['action_request'] !== '';
$backup_rows = [];
foreach (json_decode((string)$site['backups_json'], true) ?: [] as $backup) {
    $backup_rows[] = [
        'backup_id' => $escape($backup['id'] ?? ''),
        'backup_size' => $escape(round((int)($backup['size'] ?? 0) / 1048576, 1)),
        'backup_no_uploads' => ($backup['uploads'] ?? true) ? 0 : 1,
    ];
}
$app->tpl->setLoop('backups', $backup_rows);
$app->tpl->setVar('has_backups', $backup_rows === [] ? 0 : 1);
$app->tpl->setVar('action_busy', $action_busy ? 1 : 0);
$app->tpl->setVar('auto_update_on', $site['auto_update'] === 'y' ? 1 : 0);
$app->tpl->setVar('backup_uploads_on', $site['backup_uploads'] === 'y' ? 1 : 0);
$app->tpl->setVar('real_cron_on', $site['wp_cron'] === 'real' ? 1 : 0);
$app->tpl->setVar('action_status', $escape($site['action_status']));
$app->tpl->setVar('action_message', $escape($site['action_message'] ?? ''));

$check_token = $app->auth->csrf_token_get('wpm_site_check');
$app->tpl->setVar('check_csrf_id', $check_token['csrf_id']);
$app->tpl->setVar('check_csrf_key', $check_token['csrf_key']);

$csrf_token = $app->auth->csrf_token_get('wpm_site_view');
$app->tpl->setVar('_csrf_id', $csrf_token['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrf_token['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
