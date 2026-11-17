<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// New WordPress site in a folder of an existing website (example.com/blog). Only a database and its user are created,
// attached to the website; the node creates the folder, which must be new or empty, and installs WordPress there.
// The website, its users and its PHP version stay as they are.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wpstation/lib/bootstrap.php';
require_once 'lib/wpstation_config.inc.php';
require_once 'lib/wpstation_audit.inc.php';
require_once 'lib/wpstation_scope.inc.php';

$app->auth->check_module_permissions('wpstation');

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', 'templates/wpstation_site_folder.htm');

$lng_file = 'lib/lang/' . $app->functions->check_language($_SESSION['s']['language']) . '_wpstation_site_folder.lng';
include $lng_file;
$app->tpl->setVar($wb);

$escape = static fn($value): string => $app->functions->htmlentities((string)$value);
$config = wpstation_config($app);
$posted = static fn(string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';

if (isset($_POST['subfolder'])) {
    $app->auth->csrf_token_check();
    try {
        // Only a website this user may change, as in the Sites module; read again, never taken from the form.
        $website = wpstation_websites($app, (int)$posted('domain_id'))[0] ?? null;
        if (!is_array($website)) {
            throw new \WPStation\ValidationException($wb['website_invalid_txt']);
        }
        $folder = \WPStation\Subfolder::forNewSite($posted('subfolder'));
        if ($app->db->queryOneRecord('SELECT wpstation_site_id FROM wpstation_site WHERE domain = ? AND subfolder = ?', $website['domain'], $folder)) {
            throw new \WPStation\ValidationException($wb['folder_taken_txt']);
        }
        $problems = wpstation_limits_for_folder($app, (int)$website['client_id']);
        if ($problems !== []) {
            throw new \WPStation\ValidationException(implode('. ', $problems) . '.');
        }
        $request = new \WPStation\SiteRequest(
            (string)$website['domain'],
            (string)$website['client_id'],
            (string)$website['server_id'],
            $posted('wp_title'),
            $posted('wp_admin_user'),
            $posted('wp_admin_email'),
            'sftp',
            $posted('wp_locale') ?: null,
            $posted('wp_plugins'),
            $posted('wp_theme'),
            null,
            '',
            null,
            $folder,
        );
        $transport = $config->isDryRun() ? new \WPStation\DryRunTransport() : null;
        $api = \WPStation\IspconfigApi::fromConfig($config, $transport);
        $provisioner = new \WPStation\SiteProvisioner($api, $config, \WPStation\Crypto::fromFile($config->get('key_file')));
        $record = $provisioner->provisionInFolder($request, $website);
        $api->logout();
        // The address WordPress in web/ already uses is the surest guess for the scheme of the folder.
        $root = $app->db->queryOneRecord("SELECT site_url FROM wpstation_site WHERE domain_id = ? AND subfolder = '' AND site_url != ''", (int)$website['domain_id']);
        if (is_array($root) && preg_match('#^https?://#', (string)$root['site_url'])) {
            $record['site_url'] = \WPStation\Subfolder::url((string)$root['site_url'], $folder);
        }

        if ($config->isDryRun()) {
            $lines = array_map($escape, \WPStation\DryRunReport::lines($api->calls()));
            $app->tpl->setVar('error_msg', 'Dry-run: nothing was created.<br />' . implode('<br />', $lines));
        } else {
            $record = wpstation_owner($app, (int)$record['domain_id']) + $record;
            $record['sys_perm_user'] = 'riud';
            $record['sys_perm_group'] = 'riud';
            $record['sys_perm_other'] = '';
            $newId = $app->db->datalogInsert('wpstation_site', $record, 'wpstation_site_id');
            wpstation_audit($app, ['wpstation_site_id' => $newId, 'domain' => $record['domain'], 'subfolder' => $folder], 'create', $folder, "folder of the website {$record['domain']}, server {$record['server_id']}, client {$record['client_id']}");
            header('Location: wpstation_site_list.php');
            exit;
        }
    } catch (\Throwable $e) {
        $app->tpl->setVar('error_msg', $escape($e->getMessage()));
    }
}

$chosen = (int)$posted('domain_id');
$options = '';
foreach (wpstation_websites($app) as $website) {
    $selected = (int)$website['domain_id'] === $chosen ? ' selected="selected"' : '';
    $options .= '<option value="' . (int)$website['domain_id'] . '"' . $selected . '>' . $escape($website['domain']) . '</option>';
}
$app->tpl->setVar('website_options', $options);
$app->tpl->setVar('no_websites', $options === '' ? 1 : 0);

$defaults = [
    'subfolder' => '',
    'wp_title' => '',
    'wp_admin_user' => '',
    'wp_admin_email' => '',
    'wp_locale' => (string)$config->get('wordpress.locale'),
    'wp_plugins' => implode(', ', $config->get('wordpress.plugins')),
    'wp_theme' => (string)$config->get('wordpress.theme'),
];
foreach ($defaults as $name => $default) {
    $app->tpl->setVar($name, $escape(isset($_POST[$name]) ? $posted($name) : $default));
}

$csrf_token = $app->auth->csrf_token_get('wpstation_site_folder');
$app->tpl->setVar('_csrf_id', $csrf_token['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrf_token['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
