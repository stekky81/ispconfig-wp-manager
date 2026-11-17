<?php
// SPDX-License-Identifier: BSD-3-Clause

$liste['name'] = 'wpstation_site';
$liste['table'] = 'wpstation_site';
$liste['table_idx'] = 'wpstation_site_id';
$liste['search_prefix'] = 'search_';
$liste['records_per_page'] = '15';
$liste['file'] = 'wpstation_site_list.php';
$liste['edit_file'] = 'wpstation_site_edit.php';
$liste['delete_file'] = 'wpstation_site_del.php';
// Who sees what is decided on the website in ISPConfig (wpstation_site_list.php), not on the record of the module.
$liste['auth'] = 'no';

$liste['item'][] = [
    'field' => 'domain',
    'datatype' => 'VARCHAR',
    'formtype' => 'TEXT',
    'op' => 'like',
    'prefix' => '%',
    'suffix' => '%',
    'width' => '',
    'value' => '',
];

$liste['item'][] = [
    'field' => 'status',
    'datatype' => 'VARCHAR',
    'formtype' => 'SELECT',
    'op' => '=',
    'prefix' => '',
    'suffix' => '',
    'width' => '',
    'value' => [
        'queued' => 'queued',
        'installing' => 'installing',
        'installed' => 'installed',
        'error' => 'error',
        'removed' => 'removed',
    ],
];
