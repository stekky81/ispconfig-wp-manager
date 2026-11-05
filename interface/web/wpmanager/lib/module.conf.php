<?php
// SPDX-License-Identifier: BSD-3-Clause

$module['name'] = 'wpmanager';
$module['title'] = 'WordPress';
$module['template'] = 'module.tpl.htm';
$module['startpage'] = 'wpmanager/wpm_site_list.php';
$module['tab_width'] = '';
$module['order'] = '35';

$items = [];
$items[] = [
    'title' => 'WordPress sites',
    'target' => 'content',
    'link' => 'wpmanager/wpm_site_list.php',
    'html_id' => 'wpm_site_list',
];
$items[] = [
    'title' => 'New WordPress site',
    'target' => 'content',
    'link' => 'wpmanager/wpm_site_edit.php',
    'html_id' => 'wpm_site_edit',
];

$module['nav'][] = [
    'title' => 'WordPress',
    'open' => 1,
    'items' => $items,
];
