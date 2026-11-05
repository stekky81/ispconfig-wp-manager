-- SPDX-License-Identifier: BSD-3-Clause
-- Tables for ispconfig-wp-manager, created in dbispconfig on the master and on every node.
-- Idempotent: safe to run more than once.

CREATE TABLE IF NOT EXISTS `wpm_site` (
  `wpm_site_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `sys_userid` int(11) unsigned NOT NULL DEFAULT 0,
  `sys_groupid` int(11) unsigned NOT NULL DEFAULT 0,
  `sys_perm_user` varchar(5) DEFAULT NULL,
  `sys_perm_group` varchar(5) DEFAULT NULL,
  `sys_perm_other` varchar(5) DEFAULT NULL,
  `server_id` int(11) unsigned NOT NULL DEFAULT 0,
  `client_id` int(11) unsigned NOT NULL DEFAULT 0,
  `domain_id` int(11) unsigned NOT NULL DEFAULT 0,
  `origin` enum('created','detected') NOT NULL DEFAULT 'created',
  `domain` varchar(255) NOT NULL DEFAULT '',
  `site_url` varchar(255) NOT NULL DEFAULT '',
  `system_user` varchar(64) NOT NULL DEFAULT '',
  `document_root` varchar(255) NOT NULL DEFAULT '',
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `db_user` varchar(64) NOT NULL DEFAULT '',
  `db_host` varchar(255) NOT NULL DEFAULT 'localhost',
  `access_type` enum('sftp','ftp') NOT NULL DEFAULT 'sftp',
  `access_user` varchar(64) NOT NULL DEFAULT '',
  `wp_title` varchar(255) NOT NULL DEFAULT '',
  `wp_admin_user` varchar(60) NOT NULL DEFAULT '',
  `wp_admin_email` varchar(255) NOT NULL DEFAULT '',
  `wp_locale` varchar(20) NOT NULL DEFAULT '',
  `wp_plugins` varchar(1000) NOT NULL DEFAULT '',
  `wp_theme` varchar(100) NOT NULL DEFAULT '',
  -- Encrypted with the shared key, cleared by the worker after the install
  `secret_db` text,
  `secret_wp` text,
  `secret_access` text,
  `status` enum('queued','installing','installed','error') NOT NULL DEFAULT 'queued',
  `status_message` text,
  `wp_version` varchar(20) NOT NULL DEFAULT '',
  `updates_json` mediumtext,
  `updates_count` int(11) unsigned NOT NULL DEFAULT 0,
  `last_check` datetime DEFAULT NULL,
  PRIMARY KEY (`wpm_site_id`),
  UNIQUE KEY `domain` (`domain`),
  KEY `server_id` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrade from the first development versions, which could only create SFTP users.
SET @wpm_sql = (
  SELECT IF(COUNT(*) = 0, 'DO 0',
    'ALTER TABLE `wpm_site`
       CHANGE `sftp_user` `access_user` varchar(64) NOT NULL DEFAULT \'\',
       CHANGE `secret_sftp` `secret_access` text,
       ADD `access_type` enum(\'sftp\',\'ftp\') NOT NULL DEFAULT \'sftp\' AFTER `db_host`')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wpm_site' AND COLUMN_NAME = 'sftp_user'
);
PREPARE wpm_stmt FROM @wpm_sql;
EXECUTE wpm_stmt;
DEALLOCATE PREPARE wpm_stmt;

-- Global defaults edited in the panel. Used on the master only.
CREATE TABLE IF NOT EXISTS `wpm_setting` (
  `setting_name` varchar(64) NOT NULL,
  `setting_value` mediumtext,
  PRIMARY KEY (`setting_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrade from the versions without the update overview.
SET @wpm_sql = (
  SELECT IF(COUNT(*) > 0, 'DO 0',
    'ALTER TABLE `wpm_site` ADD `updates_count` int(11) unsigned NOT NULL DEFAULT 0 AFTER `updates_json`')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wpm_site' AND COLUMN_NAME = 'updates_count'
);
PREPARE wpm_stmt FROM @wpm_sql;
EXECUTE wpm_stmt;
DEALLOCATE PREPARE wpm_stmt;

-- Upgrade from the versions without the stored site URL.
SET @wpm_sql = (
  SELECT IF(COUNT(*) > 0, 'DO 0',
    'ALTER TABLE `wpm_site` ADD `site_url` varchar(255) NOT NULL DEFAULT \'\' AFTER `domain`')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wpm_site' AND COLUMN_NAME = 'site_url'
);
PREPARE wpm_stmt FROM @wpm_sql;
EXECUTE wpm_stmt;
DEALLOCATE PREPARE wpm_stmt;

-- Upgrade from the versions without the detection of existing sites.
SET @wpm_sql = (
  SELECT IF(COUNT(*) > 0, 'DO 0',
    'ALTER TABLE `wpm_site` ADD `origin` enum(\'created\',\'detected\') NOT NULL DEFAULT \'created\' AFTER `domain_id`')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wpm_site' AND COLUMN_NAME = 'origin'
);
PREPARE wpm_stmt FROM @wpm_sql;
EXECUTE wpm_stmt;
DEALLOCATE PREPARE wpm_stmt;

-- Upgrade from the versions without per-site options.
SET @wpm_sql = (
  SELECT IF(COUNT(*) > 0, 'DO 0',
    'ALTER TABLE `wpm_site`
       ADD `wp_locale` varchar(20) NOT NULL DEFAULT \'\' AFTER `wp_admin_email`,
       ADD `wp_plugins` varchar(1000) NOT NULL DEFAULT \'\' AFTER `wp_locale`,
       ADD `wp_theme` varchar(100) NOT NULL DEFAULT \'\' AFTER `wp_plugins`')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wpm_site' AND COLUMN_NAME = 'wp_locale'
);
PREPARE wpm_stmt FROM @wpm_sql;
EXECUTE wpm_stmt;
DEALLOCATE PREPARE wpm_stmt;
