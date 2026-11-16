<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Site detail page: status, install log, links and the one-time view of the generated
// passwords. Revealing them clears them from wpm_site, so they can be seen only once.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
require_once 'lib/wpm_config.inc.php';
require_once 'lib/wpm_audit.inc.php';

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
    wpm_audit($app, $site, 'check');
    $site['last_check'] = null;
    $app->tpl->setVar('check_requested', 1);
}

// Read directly from the master by the worker: nothing to hand over to the node.
if (isset($_GET['auto']) && in_array($_GET['auto'], ['y', 'n'], true)) {
    $app->auth->csrf_token_check('GET');
    $app->db->query("UPDATE wpm_site SET auto_update = ?, auto_skip = '' WHERE wpm_site_id = ?", $_GET['auto'], $id);
    wpm_audit($app, $site, 'auto_switch', $_GET['auto']);
    $site['auto_update'] = $_GET['auto'];
}

if (isset($_GET['backup_uploads']) && in_array($_GET['backup_uploads'], ['y', 'n'], true)) {
    $app->auth->csrf_token_check('GET');
    $app->db->query('UPDATE wpm_site SET backup_uploads = ? WHERE wpm_site_id = ?', $_GET['backup_uploads'], $id);
    wpm_audit($app, $site, 'backup_uploads', $_GET['backup_uploads']);
    $site['backup_uploads'] = $_GET['backup_uploads'];
}

// The request reaches the node through the datalog, like every other change.
$action = (string)($_GET['action'] ?? '');
if (in_array($action, \WpManager\SiteActions::SITE_PAGE, true)) {
    $app->auth->csrf_token_check('GET');
    $key = match ($action) {
        'restore' => 'backup',
        'update' => 'scope',
        'wpcron' => 'mode',
        default => 'param',
    };
    $param = is_string($_GET[$key] ?? null) ? $_GET[$key] : '';
    $allowed = \WpManager\SiteActions::allowedParams($action, $site);
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
            wpm_audit($app, $site, $action, $param, $action === 'password' ? \WpManager\SiteActions::adminLogin($site, $param) : '');
            $site = $update + $site;
        } catch (\Throwable $e) {
            $site['action_status'] = 'failed';
            $site['action_message'] = $e->getMessage();
        }
    }
}

// A password the worker made for this page: shown once, and only while it is valid.
if ((string)($site['secret_result'] ?? '') !== '') {
    $live = $app->db->queryOneRecord('SELECT secret_until > NOW() AS live FROM wpm_site WHERE wpm_site_id = ?', $id);
    // Cleared first, and only by one request: two pages open at once do not both show it.
    $app->db->query('UPDATE wpm_site SET secret_result = NULL, secret_until = NULL WHERE wpm_site_id = ? AND secret_result = ?', $id, $site['secret_result']);
    if ((int)($live['live'] ?? 0) === 1 && $app->db->affectedRows() === 1) {
        try {
            $result = json_decode(\WpManager\Crypto::fromFile($config->get('key_file'))->decrypt((string)$site['secret_result']), true);
        } catch (\Throwable) {
            $result = null;
        }
        if (is_array($result) && in_array($result['kind'] ?? '', ['login', 'password'], true)) {
            $app->tpl->setVar($result['kind'] . '_ready', 1);
            wpm_audit($app, $site, $result['kind'] === 'login' ? 'show_login' : 'show_password', (string)($result['user'] ?? ''));
            $app->tpl->setVar('result_user', $escape($result['user'] ?? ''));
            $app->tpl->setVar('result_password', $escape($result['password'] ?? ''));
        }
    }
    $site['secret_result'] = null;
}

if (isset($_POST['reveal'])) {
    $app->auth->csrf_token_check();
    if ($credentials->canReveal($site)) {
        $shown = $credentials->reveal($site);
        // Clear first: if this fails the passwords stay stored and nothing is shown.
        $app->db->datalogUpdate('wpm_site', ['secret_wp' => null, 'secret_access' => null, 'secret_since' => null], 'wpm_site_id', $id);
        $site['secret_wp'] = $site['secret_access'] = $site['secret_since'] = null;
        $app->tpl->setVar('revealed', 1);
        wpm_audit($app, $site, 'reveal_passwords');
        $app->tpl->setVar(array_map($escape, $shown));
    }
}

foreach (['domain', 'status', 'origin', 'access_type', 'access_user', 'wp_admin_user', 'wp_version', 'db_name', 'db_user', 'document_root', 'system_user'] as $field) {
    $app->tpl->setVar($field, $escape($site[$field]));
}
$app->tpl->setVar('id', $id);
$stagingSite = $app->db->queryOneRecord('SELECT wpm_site_id, domain FROM wpm_site WHERE staging_of = ?', $id);
$production = (int)$site['staging_of'] > 0 ? $app->db->queryOneRecord('SELECT wpm_site_id, domain FROM wpm_site WHERE wpm_site_id = ?', $site['staging_of']) : null;
$app->tpl->setVar('staging_id', is_array($stagingSite) ? (int)$stagingSite['wpm_site_id'] : 0);
$app->tpl->setVar('staging_domain', is_array($stagingSite) ? $escape($stagingSite['domain']) : '');
$app->tpl->setVar('production_id', is_array($production) ? (int)$production['wpm_site_id'] : 0);
$app->tpl->setVar('production_domain', is_array($production) ? $escape($production['domain']) : '');
$app->tpl->setVar('can_stage', $site['status'] === 'installed' && (int)$site['staging_of'] === 0 && !is_array($stagingSite) ? 1 : 0);
$app->tpl->setVar('site_url', $escape($site['site_url'] ?: 'http://' . $site['domain']));
$app->tpl->setVar('status_message', $escape($site['status_message'] ?? ''));
$app->tpl->setVar('can_reveal', $credentials->canReveal($site) ? 1 : 0);
$app->tpl->setVar('credentials_expired', \WpManager\SiteCredentials::hasExpired($site) ? 1 : 0);
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

// Maintenance tools, from the last check of the node.
$report = json_decode((string)$site['updates_json'], true);
$report = is_array($report) ? $report : [];
$app->tpl->setVar('maintenance_on', !empty($report['maintenance']) ? 1 : 0);
$app->tpl->setVar('debug_until', $escape($site['debug_until'] ?? ''));
$app->tpl->setVar('debug_elsewhere', empty($site['debug_until']) && !empty($report['debug']) ? 1 : 0);
$app->tpl->setVar('debug_tail', $escape($site['debug_tail'] ?? ''));
$app->tpl->setVar('debug_log_path', $escape(rtrim((string)$site['document_root'], '/') . '/' . \WpManager\WpDebug::LOG));
$app->tpl->setVar('site_is_http', str_starts_with((string)($site['site_url'] ?: 'http://'), 'http://') ? 1 : 0);
$app->tpl->setVar('login_active', $escape($site['login_user'] !== '' ? $site['login_until'] : ''));
$app->tpl->setVar('unused_minutes', \WpManager\SiteUsers::UNUSED_MINUTES);
$app->tpl->setVar('used_hours', intdiv(\WpManager\SiteUsers::USED_MINUTES, 60));
$app->tpl->setVar('debug_hours', \WpManager\WpDebug::HOURS);
$app->tpl->setLoop('admins', array_map(
    static fn(array $admin): array => ['admin_id' => (int)$admin['id'], 'admin_login' => $escape($admin['login'])],
    array_filter(is_array($report['admins'] ?? null) ? $report['admins'] : [], 'is_array'),
));
// Buttons only for what the action accepts now: the same list the request is checked against.
$extensionRows = static function (string $type, string $key) use ($site, $report, $escape): array {
    $can = [];
    foreach (['activate', 'deactivate', 'delete'] as $verb) {
        $can[$verb] = in_array("{$type}_{$verb}", \WpManager\SiteExtensions::ACTIONS, true)
            ? \WpManager\SiteActions::allowedParams("{$type}_{$verb}", $site) : [];
    }
    $rows = [];
    foreach (is_array($report[$key] ?? null) ? $report[$key] : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = (string)($item['name'] ?? '');
        $rows[] = [
            'ext_name' => $escape($name),
            'ext_param' => $escape(rawurlencode($name)),
            'ext_status' => $escape($item['status'] ?? ''),
            'ext_version' => $escape($item['version'] ?? ''),
            'can_activate' => in_array($name, $can['activate'], true) ? 1 : 0,
            'can_deactivate' => in_array($name, $can['deactivate'], true) ? 1 : 0,
            'can_delete' => in_array($name, $can['delete'], true) ? 1 : 0,
        ];
    }
    return $rows;
};
$app->tpl->setLoop('plugins', $extensionRows('plugin', 'plugins'));

// Security: known vulnerabilities, with the link and the notice their license asks for; permissions; hardening.
$vulnRows = [];
foreach (json_decode((string)($site['vulns_json'] ?? ''), true) ?: [] as $v) {
    if (!is_array($v)) {
        continue;
    }
    $vulnRows[] = [
        'vuln_software' => $escape(($v['type'] ?? '') . ' ' . ($v['slug'] ?? '') . ' ' . ($v['version'] ?? '')),
        'vuln_title' => $escape($v['title'] ?? ''),
        'vuln_score' => $escape(isset($v['cvss_score']) ? trim(($v['cvss_rating'] ?? '') . ' ' . $v['cvss_score']) : ''),
        'vuln_link' => $escape(str_starts_with((string)($v['link'] ?? ''), 'https://www.wordfence.com/') ? $v['link'] : ''),
        'vuln_fixed' => $escape($v['fixed_in'] ?? ''),
    ];
}
$app->tpl->setLoop('vulns', $vulnRows);
$app->tpl->setVar('has_vulns', $vulnRows === [] ? 0 : 1);
$feed = $app->db->queryOneRecord('SELECT imported, copyrights FROM wpm_vulnerability_feed ORDER BY batch DESC LIMIT 1');
$app->tpl->setVar('vuln_feed', is_array($feed) ? $escape($feed['imported']) : '');
$copyrightLines = [];
foreach (is_array($feed) ? (json_decode((string)$feed['copyrights'], true) ?: []) : [] as $copyright) {
    $copyrightLines[] = [
        'notice' => $escape($copyright['notice'] ?? ''),
        'license' => $escape($copyright['license'] ?? ''),
        'license_url' => $escape(str_starts_with((string)($copyright['license_url'] ?? ''), 'https://') ? $copyright['license_url'] : ''),
    ];
}
$app->tpl->setLoop('copyrights', $copyrightLines);
$perm = is_array($report['permissions'] ?? null) ? $report['permissions'] : [];
$app->tpl->setVar('perm_checked', $perm === [] ? 0 : 1);
$app->tpl->setVar('perm_writable', (int)($perm['world_writable'] ?? 0));
$app->tpl->setVar('perm_foreign', (int)($perm['foreign'] ?? 0));
$app->tpl->setVar('perm_config', $escape($perm['wp_config'] ?? ''));
$app->tpl->setVar('perm_config_open', \WpManager\SitePermissions::configTooOpen((string)($perm['wp_config'] ?? '')) ? 1 : 0);
$app->tpl->setVar('perm_can_fix', \WpManager\SitePermissions::needsFix($perm) ? 1 : 0);
$app->tpl->setVar('has_perm_examples', empty($perm['examples']) ? 0 : 1);
$app->tpl->setLoop('perm_examples', array_map(static fn($line): array => ['line' => $escape($line)], is_array($perm['examples'] ?? null) ? $perm['examples'] : []));
$hardeningState = is_array($report['hardening'] ?? null) ? $report['hardening'] : [];
$hardeningRows = [];
foreach (\WpManager\SiteHardening::MEASURES as $measure) {
    $hardeningRows[] = [
        'measure' => $measure,
        'measure_label' => $escape($wb['hardening_' . $measure . '_txt'] ?? $measure),
        'measure_desc' => $escape($wb['hardening_' . $measure . '_desc_txt'] ?? ''),
        'measure_on' => !empty($hardeningState[$measure]) ? 1 : 0,
        'measure_known' => array_key_exists($measure, $hardeningState) ? 1 : 0,
    ];
}
$app->tpl->setLoop('hardening', $hardeningRows);
$app->tpl->setLoop('themes', $extensionRows('theme', 'themes'));
$app->tpl->setVar('action_message', $escape($site['action_message'] ?? ''));

$audit_rows = [];
$labels = (static function () use ($app): array {
    $wb = [];
    include 'lib/lang/' . $app->functions->check_language($_SESSION['s']['language']) . '_wpm_audit.lng';
    return $wb;
})();
foreach (wpm_audit_rows($app, \WpManager\AuditLog::filters([]), $id, 10, 0) as $row) {
    $audit_rows[] = array_map($escape, $row) + ['action_label' => $escape($labels['action_' . $row['action'] . '_txt'] ?? $row['action'])];
}
$app->tpl->setLoop('audit', $audit_rows);
$app->tpl->setVar('has_audit', $audit_rows === [] ? 0 : 1);

$check_token = $app->auth->csrf_token_get('wpm_site_check');
$app->tpl->setVar('check_csrf_id', $check_token['csrf_id']);
$app->tpl->setVar('check_csrf_key', $check_token['csrf_key']);

$csrf_token = $app->auth->csrf_token_get('wpm_site_view');
$app->tpl->setVar('_csrf_id', $csrf_token['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrf_token['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
