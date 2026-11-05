<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Form definition for "New WordPress site". Only the fields the admin types are here;
// everything else is filled by SiteProvisioner in wpm_site_edit.php.

$form['title'] = 'WordPress site';
$form['description'] = '';
$form['name'] = 'wpm_site';
$form['action'] = 'wpm_site_edit.php';
$form['db_table'] = 'wpm_site';
$form['db_table_idx'] = 'wpm_site_id';
$form['db_history'] = 'yes';
$form['tab_default'] = 'site';
$form['list_default'] = 'wpm_site_list.php';
$form['auth'] = 'yes';
$form['auth_preset']['userid'] = 0;
$form['auth_preset']['groupid'] = 0;
$form['auth_preset']['perm_user'] = 'riud';
$form['auth_preset']['perm_group'] = 'riud';
$form['auth_preset']['perm_other'] = '';

$form['tabs']['site'] = [
    'title' => 'WordPress site',
    'width' => 100,
    'template' => 'templates/wpm_site_edit.htm',
    'fields' => [
        'server_id' => [
            'datatype' => 'INTEGER',
            'formtype' => 'SELECT',
            'default' => '',
            'datasource' => [
                'type' => 'SQL',
                'querystring' => "SELECT server_id,server_name FROM server WHERE mirror_server_id = 0 AND web_server = 1 AND {AUTHSQL} ORDER BY server_name",
                'keyfield' => 'server_id',
                'valuefield' => 'server_name',
            ],
            'value' => '',
        ],
        'client_id' => [
            'datatype' => 'INTEGER',
            'formtype' => 'SELECT',
            'default' => '',
            'datasource' => [
                'type' => 'SQL',
                'querystring' => "SELECT client_id, CONCAT(IF(company_name != '', CONCAT(company_name, ' :: '), ''), contact_name, ' (', username, ')') AS name FROM client WHERE {AUTHSQL} ORDER BY company_name, contact_name",
                'keyfield' => 'client_id',
                'valuefield' => 'name',
            ],
            'value' => '',
        ],
        'domain' => [
            'datatype' => 'VARCHAR',
            'formtype' => 'TEXT',
            'filters' => [
                0 => ['event' => 'SAVE', 'type' => 'IDNTOASCII'],
                1 => ['event' => 'SHOW', 'type' => 'IDNTOUTF8'],
                2 => ['event' => 'SAVE', 'type' => 'TOLOWER'],
            ],
            'validators' => [
                0 => ['type' => 'NOTEMPTY', 'errmsg' => 'domain_error_empty'],
                1 => ['type' => 'UNIQUE', 'errmsg' => 'domain_error_unique'],
            ],
            'default' => '',
            'value' => '',
            'width' => '30',
            'maxlength' => '255',
        ],
        'wp_title' => [
            'datatype' => 'VARCHAR',
            'formtype' => 'TEXT',
            'validators' => [0 => ['type' => 'NOTEMPTY', 'errmsg' => 'wp_title_error_empty']],
            'default' => '',
            'value' => '',
            'width' => '30',
            'maxlength' => '200',
        ],
        'wp_admin_user' => [
            'datatype' => 'VARCHAR',
            'formtype' => 'TEXT',
            'validators' => [0 => ['type' => 'NOTEMPTY', 'errmsg' => 'wp_admin_user_error_empty']],
            'default' => '',
            'value' => '',
            'width' => '30',
            'maxlength' => '60',
        ],
        'wp_admin_email' => [
            'datatype' => 'VARCHAR',
            'formtype' => 'TEXT',
            'validators' => [0 => ['type' => 'ISEMAIL', 'errmsg' => 'wp_admin_email_error_isemail']],
            'default' => '',
            'value' => '',
            'width' => '30',
            'maxlength' => '255',
        ],
        'access_type' => [
            'datatype' => 'VARCHAR',
            'formtype' => 'SELECT',
            'default' => 'sftp',
            'value' => ['sftp' => 'SFTP', 'ftp' => 'FTP'],
        ],
    ],
];
