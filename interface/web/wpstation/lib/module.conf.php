<?php
// SPDX-License-Identifier: BSD-3-Clause

$module['name'] = 'wpstation';
$module['title'] = 'WordPress';
$module['icon'] = 'bi-icon bi bi-wordpress';
$module['template'] = 'module.tpl.htm';
$module['startpage'] = 'wpstation/wpstation_site_list.php';
$module['tab_width'] = '';
$module['order'] = '35';

$items = [];
$items[] = [
    'title' => 'WordPress sites',
    'target' => 'content',
    'link' => 'wpstation/wpstation_site_list.php',
    'html_id' => 'wpstation_site_list',
];
$items[] = [
    'title' => 'New WordPress site',
    'target' => 'content',
    'link' => 'wpstation/wpstation_site_edit.php',
    'html_id' => 'wpstation_site_edit',
];

$items[] = [
    'title' => 'Updates',
    'target' => 'content',
    'link' => 'wpstation/wpstation_updates.php',
    'html_id' => 'wpstation_updates',
];
// The log of every site and the settings are for admins only.
if (($_SESSION['s']['user']['typ'] ?? '') === 'admin') {
$items[] = [
    'title' => 'Log',
    'target' => 'content',
    'link' => 'wpstation/wpstation_audit.php',
    'html_id' => 'wpstation_audit',
];
$items[] = [
    'title' => 'Settings',
    'target' => 'content',
    'link' => 'wpstation/wpstation_settings.php',
    'html_id' => 'wpstation_settings',
];
}

$module['nav'][] = [
    'title' => 'WordPress',
    'open' => 1,
    'items' => $items,
];
