<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// ISPConfig server module. server.sh hands a datalog record only to the modules that
// registered its table, and a module then raises the events plugins listen to.
// This one does that for wpstation_site, so that wpstation_plugin receives its events.

class wpstation_module
{
    public $module_name = 'wpstation_module';
    public $class_name = 'wpstation_module';
    public $actions_available = ['wpstation_site_insert', 'wpstation_site_update', 'wpstation_site_delete'];

    public function onInstall()
    {
        global $conf;
        return isset($conf['services']['web']) && $conf['services']['web'] === true;
    }

    public function onLoad()
    {
        global $app;
        $app->plugins->announceEvents($this->module_name, $this->actions_available);
        $app->modules->registerTableHook('wpstation_site', $this->module_name, 'process');
    }

    public function process($tablename, $action, $data)
    {
        global $app;

        $events = ['i' => 'wpstation_site_insert', 'u' => 'wpstation_site_update', 'd' => 'wpstation_site_delete'];
        if (isset($events[$action])) {
            $app->plugins->raiseEvent($events[$action], $data);
        }
    }
}
