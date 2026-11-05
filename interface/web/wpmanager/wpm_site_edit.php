<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// "New WordPress site" page. On save it provisions the ISPConfig objects through the
// Remote API (SiteProvisioner) and then stores the wpm_site record through the datalog,
// which hands the WordPress install over to the target node.
//
// VERIFY against the installed ISPConfig version: tform_actions method names and flow.

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
            );
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
            $app->log('wpmanager dry-run: ' . json_encode($api->calls()), LOGLEVEL_DEBUG);
            $app->tform->errorMessage .= 'Dry-run: nothing was created. API calls written to the ISPConfig log.<br />';
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
