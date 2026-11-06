<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Clones a site into a new website of the same node. The ISPConfig objects are created like
// for a new site; the worker then copies the files and the database instead of installing.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
require_once 'lib/wpm_config.inc.php';

$app->auth->check_module_permissions('wpmanager');

if ($_SESSION['s']['user']['typ'] !== 'admin') {
    $app->error('Only administrators can clone WordPress sites in this version.');
}

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', 'templates/wpm_site_clone.htm');

$lng_file = 'lib/lang/' . $app->functions->check_language($_SESSION['s']['language']) . '_wpm_site_clone.lng';
include $lng_file;
$app->tpl->setVar($wb);

$escape = static fn($value): string => $app->functions->htmlentities((string)$value);

$id = $app->functions->intval($_REQUEST['id'] ?? 0);
$source = $app->db->queryOneRecord("SELECT * FROM wpm_site WHERE wpm_site_id = ? AND status = 'installed'", $id);
if (!is_array($source)) {
    $app->error($wb['source_not_found_txt']);
}

$config = wpm_config($app);

if (isset($_POST['domain'])) {
    $app->auth->csrf_token_check();
    try {
        $domain = \WpManager\Validator::domain((string)$_POST['domain']);
        if ($app->db->queryOneRecord('SELECT wpm_site_id FROM wpm_site WHERE domain = ?', $domain)) {
            throw new \WpManager\ValidationException($wb['domain_taken_txt']);
        }
        // Stays when the module opens to clients and the check at the top of this page goes.
        if (!\WpManager\SiteCloner::mayCloneToClient((string)$_SESSION['s']['user']['typ'], (int)$source['client_id'], (int)$_POST['client_id'])) {
            throw new \WpManager\ValidationException($wb['client_denied_txt']);
        }
        // The code of the source will run in the account of the other client.
        if ((int)$_POST['client_id'] !== (int)$source['client_id'] && empty($_POST['confirm_client'])) {
            throw new \WpManager\ValidationException($wb['confirm_client_error_txt']);
        }
        $website = $app->db->queryOneRecord('SELECT server_php_id FROM web_domain WHERE domain_id = ?', $source['domain_id']);
        // Title and admin belong to the source: its database replaces them.
        $request = new \WpManager\SiteRequest(
            $domain,
            (string)$_POST['client_id'],
            (string)$source['server_id'],
            'Clone of ' . $source['domain'],
            'clone-admin',
            'noreply@' . $domain,
            (string)($_POST['access_type'] ?? ''),
            null,
            '',
            '',
            (string)(int)($website['server_php_id'] ?? 0),
        );
        $transport = $config->isDryRun() ? new \WpManager\DryRunTransport() : null;
        $api = \WpManager\IspconfigApi::fromConfig($config, $transport);
        $provisioner = new \WpManager\SiteProvisioner($api, $config, \WpManager\Crypto::fromFile($config->get('key_file')));
        $record = $provisioner->provision($request);
        $api->logout();

        if ($config->isDryRun()) {
            $lines = array_map($escape, \WpManager\DryRunReport::lines($api->calls()));
            $app->tpl->setVar('error_msg', 'Dry-run: nothing was created.<br />' . implode('<br />', $lines));
        } else {
            unset($record['secret_wp']);
            $record['wp_admin_user'] = '';
            $record['wp_title'] = '';
            $record['clone_of'] = (int)$source['wpm_site_id'];
            $record['sys_userid'] = (int)$_SESSION['s']['user']['userid'];
            $record['sys_groupid'] = (int)$_SESSION['s']['user']['default_group'];
            $record['sys_perm_user'] = 'riud';
            $record['sys_perm_group'] = 'riud';
            $record['sys_perm_other'] = '';
            $app->db->datalogInsert('wpm_site', $record, 'wpm_site_id');
            header('Location: wpm_site_list.php');
            exit;
        }
    } catch (\Throwable $e) {
        $app->tpl->setVar('error_msg', $escape($e->getMessage()));
    }
}

$clients = '';
foreach ($app->db->queryAllRecords("SELECT client_id, CONCAT(IF(company_name != '', CONCAT(company_name, ' :: '), ''), contact_name, ' (', username, ')') AS name FROM client ORDER BY company_name, contact_name") as $client) {
    $selected = (int)$client['client_id'] === (int)$source['client_id'] ? ' selected="selected"' : '';
    $clients .= '<option value="' . (int)$client['client_id'] . '"' . $selected . '>' . $escape($client['name']) . '</option>';
}

$app->tpl->setVar('id', $id);
$app->tpl->setVar('source_domain', $escape($source['domain']));
$app->tpl->setVar('domain', $escape($_POST['domain'] ?? ''));
$app->tpl->setVar('client_options', $clients);

$csrf_token = $app->auth->csrf_token_get('wpm_site_clone');
$app->tpl->setVar('_csrf_id', $csrf_token['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrf_token['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
