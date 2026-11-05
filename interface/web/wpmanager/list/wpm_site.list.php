<?php
// SPDX-License-Identifier: BSD-3-Clause

$liste['name'] = 'wpm_site';
$liste['table'] = 'wpm_site';
$liste['table_idx'] = 'wpm_site_id';
$liste['search_prefix'] = 'search_';
$liste['records_per_page'] = '15';
$liste['file'] = 'wpm_site_list.php';
$liste['edit_file'] = 'wpm_site_edit.php';
$liste['delete_file'] = 'wpm_site_del.php';
$liste['paging_tpl'] = 'templates/paging.tpl.htm';
$liste['auth'] = 'yes';

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
    ],
];
