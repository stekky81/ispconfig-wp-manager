<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Global defaults for new sites. dry_run and the Remote API account stay in config.php.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
require_once 'lib/wpm_config.inc.php';

$app->auth->check_module_permissions('wpmanager');

if ($_SESSION['s']['user']['typ'] !== 'admin') {
    $app->error('Only administrators can change the WordPress settings.');
}

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', 'templates/wpm_settings.htm');

$lng_file = 'lib/lang/' . $app->functions->check_language($_SESSION['s']['language']) . '_wpm_settings.lng';
include $lng_file;
$app->tpl->setVar($wb);

$escape = static fn($value): string => $app->functions->htmlentities((string)$value);

if (isset($_POST['locale'])) {
    $app->auth->csrf_token_check();
    try {
        $settings = \WpManager\Settings::fromInput($_POST);
        $app->db->query(
            'REPLACE INTO wpm_setting (setting_name, setting_value) VALUES (?, ?)',
            'defaults',
            json_encode($settings, JSON_THROW_ON_ERROR),
        );
        $app->tpl->setVar('saved', 1);
    } catch (\WpManager\ValidationException $e) {
        $app->tpl->setVar('error_msg', $escape($e->getMessage()));
    }
}

$config = wpm_config($app);
$d = $config->get('site_defaults');
$wp = $config->get('wordpress');

$options = static function (array $values, $current) use ($escape): string {
    $html = '';
    foreach ($values as $value => $label) {
        $selected = (string)$value === (string)$current ? ' selected="selected"' : '';
        $html .= '<option value="' . $escape($value) . '"' . $selected . '>' . $escape($label) . '</option>';
    }
    return $html;
};

$php = ['0' => 'Default'];
foreach ($app->db->queryAllRecords("SELECT server_php_id, name FROM server_php WHERE active = 'y' ORDER BY name") as $row) {
    $php[$row['server_php_id']] = $row['name'];
}

$yesNo = ['y' => $wb['yes_txt'], 'n' => $wb['no_txt']];
$app->tpl->setVar([
    'server_php_id_options' => $options($php, $d['server_php_id']),
    'ssl_options' => $options($yesNo, $d['ssl']),
    'ssl_letsencrypt_options' => $options($yesNo, $d['ssl_letsencrypt']),
    'subdomain_options' => $options(['none' => 'none', 'www' => 'www', '*' => '*'], $d['subdomain']),
    'hd_quota' => $escape($d['hd_quota']),
    'traffic_quota' => $escape($d['traffic_quota']),
    'locale' => $escape($wp['locale']),
    'plugins' => $escape(implode(', ', $wp['plugins'])),
    'theme' => $escape($wp['theme']),
    'dry_run' => $config->isDryRun() ? 1 : 0,
]);

$csrf_token = $app->auth->csrf_token_get('wpm_settings');
$app->tpl->setVar('_csrf_id', $csrf_token['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrf_token['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
