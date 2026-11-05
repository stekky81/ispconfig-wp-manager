<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Copy to /opt/ispconfig-wp-manager/config.php (chmod 600, owner root) and edit.
// Only the keys you set here override the built-in defaults in lib/Config.php.

return [
    // Keep true until the test plan in docs/testing.md has passed on your setup.
    // In dry-run mode the form lists every Remote API call, but nothing is changed.
    'dry_run' => true,

    'remote_api' => [
        'url' => 'https://panel.example.com:8080/remote/json.php',
        'user' => 'wpmanager',
        'password' => 'change-me',
        'verify_tls' => true,
    ],

    'site_defaults' => [
        'php' => 'php-fpm',
        'server_php_id' => 0,          // 0 = default PHP of the server; see System → Additional PHP Versions
        'hd_quota' => -1,              // MB, -1 = unlimited
        'traffic_quota' => -1,
        'ssl' => 'y',
        'ssl_letsencrypt' => 'y',
        'subdomain' => 'www',
        'database_server_id' => null,  // null = same server as the website
        'db_host' => 'localhost',
        'db_prefix' => 'c[CLIENT_ID]_',
    ],

    // Plugins, themes and minor core releases of every site, also the ones found on the nodes.
    // Starts as soon as dry_run is off: set 'n' to look at a node without changing it.
    'updates' => [
        'auto' => 'y',
    ],

    'wordpress' => [
        'locale' => 'it_IT',
        'plugins' => [
            // 'wordfence',
        ],
        'theme' => null,
        'constants' => [
            // name => [value, raw]
            'DISALLOW_FILE_EDIT' => ['true', true],
            'WP_POST_REVISIONS' => ['10', true],
        ],
    ],
];
