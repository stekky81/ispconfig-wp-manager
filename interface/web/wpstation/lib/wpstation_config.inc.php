<?php
// SPDX-License-Identifier: BSD-3-Clause

/** The configuration of the panel: config.php with the settings saved in wpstation_setting on top. */
function wpstation_config($app): \WPStation\Config
{
    $config = \WPStation\Config::load();
    $row = $app->db->queryOneRecord("SELECT setting_value FROM wpstation_setting WHERE setting_name = 'defaults'");
    return is_array($row)
        ? $config->withOverrides(\WPStation\Settings::fromJson((string)$row['setting_value']))
        : $config;
}
