<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// "New WordPress site" page. On save it provisions the ISPConfig objects through the
// Remote API (SiteProvisioner) and then stores the wpstation_site record through the datalog,
// which hands the WordPress install over to the target node.

$tform_def_file = 'form/wpstation_site.tform.php';

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wpstation/lib/bootstrap.php';
require_once 'lib/wpstation_config.inc.php';
require_once 'lib/wpstation_audit.inc.php';
require_once 'lib/wpstation_scope.inc.php';

$app->auth->check_module_permissions('wpstation');

/**
 * For clients and resellers: their clients, the web servers of the chosen client and the PHP versions they may use,
 * never what the SQL of the form would list for an admin.
 *
 * @return array{clients: array<int, string>, servers: array<int, string>}
 */
function wpstation_choices($app, ?int $clientId = null): array
{
    $clients = [];
    foreach (wpstation_allowed_clients($app) ?? [] as $id) {
        $row = $app->db->queryOneRecord("SELECT client_id, CONCAT(IF(company_name != '', CONCAT(company_name, ' :: '), ''), contact_name, ' (', username, ')') AS name FROM client WHERE client_id = ?", $id);
        if (is_array($row)) {
            $clients[(int)$row['client_id']] = (string)$row['name'];
        }
    }
    $client = $app->db->queryOneRecord('SELECT * FROM client WHERE client_id = ?', $clientId ?? array_key_first($clients) ?? 0);
    $allowed = is_array($client) ? \WPStation\ClientLimits::webServers($client) : [];
    $servers = [];
    foreach ($app->db->queryAllRecords('SELECT server_id, server_name FROM server WHERE mirror_server_id = 0 AND web_server = 1 ORDER BY server_name') ?: [] as $row) {
        if ($allowed === [] || in_array((int)$row['server_id'], $allowed, true)) {
            $servers[(int)$row['server_id']] = (string)$row['server_name'];
        }
    }
    return ['clients' => $clients, 'servers' => $servers];
}

$app->uses('tpl,tform,tform_actions');
$app->load('tform_actions');

class page_action extends tform_actions
{
    public function onShowNew()
    {
        global $app;

        // Pre-fill the per-site options with the global defaults.
        $config = wpstation_config($app);
        $fields = &$app->tform->formDef['tabs']['site']['fields'];
        $fields['wp_locale']['default'] = $config->get('wordpress.locale');
        $fields['wp_plugins']['default'] = implode(', ', $config->get('wordpress.plugins'));
        $fields['wp_theme']['default'] = (string)$config->get('wordpress.theme');
        $fields['server_php_id']['default'] = (string)$config->get('site_defaults.server_php_id');
        if (!wpstation_is_admin()) {
            $choices = wpstation_choices($app);
            unset($fields['client_id']['datasource'], $fields['server_id']['datasource']);
            $fields['client_id']['value'] = $choices['clients'];
            $fields['server_id']['value'] = $choices['servers'];
            $fields['server_php_id']['datasource']['querystring'] = "SELECT server_php_id, name FROM server_php WHERE active = 'y' AND client_id IN (0, "
                . (int)($_SESSION['s']['user']['client_id'] ?? 0) . ') ORDER BY name';
            // The web space left in the package, for the first client of the list.
            $first = (int)(array_key_first($choices['clients']) ?? 0);
            $fit = wpstation_limits_for_new_site($app, $first, 'sftp', (int)$config->get('site_defaults.hd_quota'));
            $app->tpl->setVar('quota_field', 1);
            $app->tpl->setVar('hd_quota', $fit['quota'] < 0 ? '' : (string)$fit['quota']);
            $app->tpl->setVar('quota_problems', htmlspecialchars(implode('. ', $fit['problems']), ENT_QUOTES));
        }

        parent::onShowNew();
    }

    public function onShowEdit()
    {
        // Existing sites are read-only in the MVP: go back to the list.
        header('Location: wpstation_site_list.php');
        exit;
    }

    public function onUpdate()
    {
        $this->onShowEdit();
    }

    public function onInsert()
    {
        global $app;

        // Run the standard form validators and the CSRF check, without saving. The tab is explicit:
        // getCurrentTab() is empty when the form was never opened in this session, and with an
        // empty tab getSQL() returns at once, so a forged request would skip every check.
        $app->tform->getSQL($this->dataRecord, 'site', 'INSERT', 0, '');
        if ($app->tform->errorMessage !== '') {
            $this->onError();
            return;
        }

        try {
            $config = wpstation_config($app);
            $quota = (int)$config->get('site_defaults.hd_quota');
            if (!wpstation_is_admin()) {
                // The options of the form are only a help: what was posted is checked again here.
                $clientId = (int)$this->dataRecord['client_id'];
                $choices = wpstation_choices($app, $clientId);
                if (!isset($choices['clients'][$clientId])) {
                    throw new \WPStation\ValidationException('You cannot create sites for this client');
                }
                if (!isset($choices['servers'][(int)$this->dataRecord['server_id']])) {
                    throw new \WPStation\ValidationException('This client cannot use this web server');
                }
                $php = (int)($this->dataRecord['server_php_id'] ?? 0);
                if ($php > 0 && !is_array($app->db->queryOneRecord('SELECT server_php_id FROM server_php WHERE server_php_id = ? AND client_id IN (0, ?)', $php, $clientId))) {
                    throw new \WPStation\ValidationException('This client cannot use this PHP version');
                }
                $fit = wpstation_limits_for_new_site($app, $clientId, (string)($this->dataRecord['access_type'] ?? 'sftp'), $quota);
                if ($fit['problems'] !== []) {
                    throw new \WPStation\ValidationException(implode('. ', $fit['problems']) . '.');
                }
                $asked = trim(is_string($_POST['hd_quota'] ?? null) ? $_POST['hd_quota'] : '');
                $quota = $fit['quota'];
                if ($asked !== '') {
                    if (!ctype_digit($asked) || (int)$asked < \WPStation\ClientLimits::MIN_QUOTA_MB || ($fit['quota'] >= 0 && (int)$asked > $fit['quota'])) {
                        throw new \WPStation\ValidationException('Web space must be between ' . \WPStation\ClientLimits::MIN_QUOTA_MB . ' and ' . ($fit['quota'] < 0 ? 'any' : $fit['quota']) . ' MB');
                    }
                    $quota = (int)$asked;
                }
            }
            $request = new \WPStation\SiteRequest(
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
                is_string($_POST['ssh_keys'] ?? null) ? $_POST['ssh_keys'] : '',
                $quota,
            );
            // The Remote API accepts any server id, so a typo would create a site with no database.
            $dbServerId = $config->get('site_defaults.database_server_id') ?? $request->serverId;
            if (!is_array($app->db->queryOneRecord('SELECT server_id FROM server WHERE server_id = ? AND db_server = 1', $dbServerId))) {
                throw new \WPStation\ValidationException("Server {$dbServerId} is not a database server: check database_server_id in config.php");
            }

            $transport = $config->isDryRun() ? new \WPStation\DryRunTransport() : null;
            $api = \WPStation\IspconfigApi::fromConfig($config, $transport);
            $provisioner = new \WPStation\SiteProvisioner(
                $api,
                $config,
                \WPStation\Crypto::fromFile($config->get('key_file')),
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
            foreach (\WPStation\DryRunReport::lines($api->calls()) as $line) {
                $app->tform->errorMessage .= htmlspecialchars($line, ENT_QUOTES) . '<br />';
            }
            $this->onError();
            return;
        }

        // The record belongs to whoever owns the website in ISPConfig.
        $record = wpstation_owner($app, (int)$record['domain_id']) + $record;
        $record['sys_perm_user'] = 'riud';
        $record['sys_perm_group'] = 'riud';
        $record['sys_perm_other'] = '';

        $this->id = $app->db->datalogInsert('wpstation_site', $record, 'wpstation_site_id');
        wpstation_audit($app, ['wpstation_site_id' => $this->id, 'domain' => $record['domain']], 'create', '', "server {$record['server_id']}, client {$record['client_id']}");

        header('Location: wpstation_site_list.php');
        exit;
    }
}

$page = new page_action;
$page->onLoad();
