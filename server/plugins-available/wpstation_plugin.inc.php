<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// ISPConfig server plugin. server.sh calls it when a wpstation_site datalog record for this
// node is processed. It only queues a job: the slow work is done by bin/wpstation-worker.php,
// so server.sh is never blocked by WP-CLI.

class wpstation_plugin
{
    public $plugin_name = 'wpstation_plugin';
    public $class_name = 'wpstation_plugin';

    public function onInstall()
    {
        global $conf;
        return isset($conf['services']['web']) && $conf['services']['web'] === true;
    }

    public function onLoad()
    {
        global $app;
        $app->plugins->registerEvent('wpstation_site_insert', $this->plugin_name, 'queue');
        $app->plugins->registerEvent('wpstation_site_update', $this->plugin_name, 'queue');
        // wpstation_site_delete: intentionally nothing. Removing the record never touches the site.
    }

    public function queue($event_name, $data)
    {
        global $app;

        $site = $data['new'] ?? [];

        // An action asked in the panel: the worker reads the current record itself.
        $action = (string)($site['action_request'] ?? '');
        require_once '/opt/ispconfig-wpstation/lib/bootstrap.php';
        if (in_array($action, \WPStation\SiteActions::ALL, true)) {
            try {
                $queue = new \WPStation\JobQueue(\WPStation\Config::loadTrusted()->get('spool_dir'));
                $queue->push($action, (int)$site['wpstation_site_id'], []);
            } catch (\Throwable $e) {
                $app->log('wpstation: cannot queue ' . $action . ': ' . $e->getMessage(), LOGLEVEL_WARN);
            }
            return;
        }

        if (($site['status'] ?? '') !== 'queued' || (int)($site['domain_id'] ?? 0) < 1) {
            return;
        }

        try {
            $config = \WPStation\Config::loadTrusted();
            $queue = new \WPStation\JobQueue($config->get('spool_dir'));
            $queue->push('install', (int)$site['wpstation_site_id'], $site);
            $app->log('wpstation: queued install of ' . $site['domain'], LOGLEVEL_DEBUG);
        } catch (\Throwable $e) {
            $app->log('wpstation: cannot queue ' . ($site['domain'] ?? '?') . ': ' . $e->getMessage(), LOGLEVEL_WARN);
        }
    }
}
