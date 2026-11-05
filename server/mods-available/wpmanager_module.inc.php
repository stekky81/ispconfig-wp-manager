<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// ISPConfig server module. server.sh hands a datalog record only to the modules that
// registered its table, and a module then raises the events plugins listen to.
// This one does that for wpm_site, so that wpmanager_plugin receives its events.

class wpmanager_module
{
    public $module_name = 'wpmanager_module';
    public $class_name = 'wpmanager_module';
    public $actions_available = ['wpm_site_insert', 'wpm_site_update', 'wpm_site_delete'];

    public function onInstall()
    {
        global $conf;
        return isset($conf['services']['web']) && $conf['services']['web'] === true;
    }

    public function onLoad()
    {
        global $app;
        $app->plugins->announceEvents($this->module_name, $this->actions_available);
        $app->modules->registerTableHook('wpm_site', $this->module_name, 'process');
    }

    public function process($tablename, $action, $data)
    {
        global $app;

        $events = ['i' => 'wpm_site_insert', 'u' => 'wpm_site_update', 'd' => 'wpm_site_delete'];
        if (isset($events[$action])) {
            $app->plugins->raiseEvent($events[$action], $data);
        }
    }
}
