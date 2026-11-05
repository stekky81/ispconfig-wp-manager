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
  `domain` varchar(255) NOT NULL DEFAULT '',
  `system_user` varchar(64) NOT NULL DEFAULT '',
  `document_root` varchar(255) NOT NULL DEFAULT '',
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `db_user` varchar(64) NOT NULL DEFAULT '',
  `db_host` varchar(255) NOT NULL DEFAULT 'localhost',
  `sftp_user` varchar(64) NOT NULL DEFAULT '',
  `wp_title` varchar(255) NOT NULL DEFAULT '',
  `wp_admin_user` varchar(60) NOT NULL DEFAULT '',
  `wp_admin_email` varchar(255) NOT NULL DEFAULT '',
  -- Encrypted with the shared key, cleared by the worker after the install
  `secret_db` text,
  `secret_wp` text,
  `secret_sftp` text,
  `status` enum('queued','installing','installed','error') NOT NULL DEFAULT 'queued',
  `status_message` text,
  `wp_version` varchar(20) NOT NULL DEFAULT '',
  `updates_json` mediumtext,
  `last_check` datetime DEFAULT NULL,
  PRIMARY KEY (`wpm_site_id`),
  UNIQUE KEY `domain` (`domain`),
  KEY `server_id` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
