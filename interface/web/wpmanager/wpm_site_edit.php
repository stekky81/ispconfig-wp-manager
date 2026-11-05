<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// "New WordPress site" page. On save it provisions the ISPConfig objects through the
// Remote API (SiteProvisioner) and then stores the wpm_site record through the datalog,
// which hands the WordPress install over to the target node.

$tform_def_file = 'form/wpm_site.tform.php';

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';

$app->auth->check_module_permissions('wpmanager');

// MVP: admins only. Clients and resellers come later, with quota checks.
if ($_SESSION['s']['user']['typ'] !== 'admin') {
    $app->error('Only administrators can create WordPress sites in this version.');
}

$app->uses('tpl,tform,tform_actions');
$app->load('tform_actions');

class page_action extends tform_actions
{
    public function onShowNew()
    {
        global $app;

        // Pre-fill the per-site options with the global defaults.
        $config = \WpManager\Config::load();
        $fields = &$app->tform->formDef['tabs']['site']['fields'];
        $fields['wp_locale']['default'] = $config->get('wordpress.locale');
        $fields['wp_plugins']['default'] = implode(', ', $config->get('wordpress.plugins'));
        $fields['wp_theme']['default'] = (string)$config->get('wordpress.theme');
        $fields['server_php_id']['default'] = (string)$config->get('site_defaults.server_php_id');

        parent::onShowNew();
    }

    public function onShowEdit()
    {
        // Existing sites are read-only in the MVP: go back to the list.
        header('Location: wpm_site_list.php');
        exit;
    }

    public function onUpdate()
    {
        $this->onShowEdit();
    }

    public function onInsert()
    {
        global $app;

        // Run the standard form validators without saving.
        $app->tform->getSQL($this->dataRecord, $app->tform->getCurrentTab(), 'INSERT', 0, '');
        if ($app->tform->errorMessage !== '') {
            $this->onError();
            return;
        }

        try {
            $config = \WpManager\Config::load();
            $request = new \WpManager\SiteRequest(
                (string)$this->dataRecord['domain'],
                $this->dataRecord['client_id'],
                $this->dataRecord['server_id'],
                (string)$this->dataRecord['wp_title'],
                (string)$this->dataRecord['wp_admin_user'],
                (string)$this->dataRecord['wp_admin_email'],
                (string)($this->dataRecord['access_type'] ?? ''),
                (string)($this->dataRecord['wp_locale'] ?? '') ?: null,
                (string)($this->dataRecord['wp_plugins'] ?? ''),
                (string)($this->dataRecord['wp_theme'] ?? ''),
                $this->dataRecord['server_php_id'] ?? '0',
            );
            // The Remote API accepts any server id, so a typo would create a site with no database.
            $dbServerId = $config->get('site_defaults.database_server_id') ?? $request->serverId;
            if (!is_array($app->db->queryOneRecord('SELECT server_id FROM server WHERE server_id = ? AND db_server = 1', $dbServerId))) {
                throw new \WpManager\ValidationException("Server {$dbServerId} is not a database server: check database_server_id in config.php");
            }

            $transport = $config->isDryRun() ? new \WpManager\DryRunTransport() : null;
            $api = \WpManager\IspconfigApi::fromConfig($config, $transport);
            $provisioner = new \WpManager\SiteProvisioner(
                $api,
                $config,
                \WpManager\Crypto::fromFile($config->get('key_file')),
            );
            $record = $provisioner->provision($request);
            $api->logout();
        } catch (\Throwable $e) {
            $app->tform->errorMessage .= htmlspecialchars($e->getMessage(), ENT_QUOTES) . '<br />';
            $this->onError();
            return;
        }

        if ($config->isDryRun()) {
            $app->tform->errorMessage .= 'Dry-run: nothing was created. These Remote API calls would be made:<br />';
            foreach (\WpManager\DryRunReport::lines($api->calls()) as $line) {
                $app->tform->errorMessage .= htmlspecialchars($line, ENT_QUOTES) . '<br />';
            }
            $this->onError();
            return;
        }

        $record['sys_userid'] = (int)$_SESSION['s']['user']['userid'];
        $record['sys_groupid'] = (int)$_SESSION['s']['user']['default_group'];
        $record['sys_perm_user'] = 'riud';
        $record['sys_perm_group'] = 'riud';
        $record['sys_perm_other'] = '';

        $this->id = $app->db->datalogInsert('wpm_site', $record, 'wpm_site_id');

        header('Location: wpm_site_list.php');
        exit;
    }
}

$page = new page_action;
$page->onLoad();
