<?php
// SPDX-License-Identifier: BSD-3-Clause

/** The configuration of the panel: config.php with the settings saved in wpm_setting on top. */
function wpm_config($app): \WpManager\Config
{
    $config = \WpManager\Config::load();
    $row = $app->db->queryOneRecord("SELECT setting_value FROM wpm_setting WHERE setting_name = 'defaults'");
    return is_array($row)
        ? $config->withOverrides(\WpManager\Settings::fromJson((string)$row['setting_value']))
        : $config;
}
