<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// ISPConfig server plugin. server.sh calls it when a wpm_site datalog record for this
// node is processed. It only queues a job: the slow work is done by bin/wpm-worker.php,
// so server.sh is never blocked by WP-CLI.

class wpmanager_plugin
{
    public $plugin_name = 'wpmanager_plugin';
    public $class_name = 'wpmanager_plugin';

    public function onInstall()
    {
        global $conf;
        return isset($conf['services']['web']) && $conf['services']['web'] === true;
    }

    public function onLoad()
    {
        global $app;
        $app->plugins->registerEvent('wpm_site_insert', $this->plugin_name, 'queue');
        $app->plugins->registerEvent('wpm_site_update', $this->plugin_name, 'queue');
        // wpm_site_delete: intentionally nothing. Removing the record never touches the site.
    }

    public function queue($event_name, $data)
    {
        global $app;

        $site = $data['new'] ?? [];

        // An action asked in the panel: the worker reads the current record itself.
        $action = (string)($site['action_request'] ?? '');
        if (in_array($action, ['backup', 'restore'], true)) {
            try {
                require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
                $queue = new \WpManager\JobQueue(\WpManager\Config::load()->get('spool_dir'));
                $queue->push($action, (int)$site['wpm_site_id'], []);
            } catch (\Throwable $e) {
                $app->log('wpmanager: cannot queue ' . $action . ': ' . $e->getMessage(), LOGLEVEL_WARN);
            }
            return;
        }

        if (($site['status'] ?? '') !== 'queued' || (int)($site['domain_id'] ?? 0) < 1) {
            return;
        }

        try {
            require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
            $config = \WpManager\Config::load();
            $queue = new \WpManager\JobQueue($config->get('spool_dir'));
            $queue->push('install', (int)$site['wpm_site_id'], $site);
            $app->log('wpmanager: queued install of ' . $site['domain'], LOGLEVEL_DEBUG);
        } catch (\Throwable $e) {
            $app->log('wpmanager: cannot queue ' . ($site['domain'] ?? '?') . ': ' . $e->getMessage(), LOGLEVEL_WARN);
        }
    }
}
