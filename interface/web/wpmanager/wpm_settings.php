<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Global defaults for new sites. dry_run and the Remote API account stay in config.php.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
require_once 'lib/wpm_config.inc.php';
require_once 'lib/wpm_audit.inc.php';

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
        $previous = $app->db->queryOneRecord("SELECT setting_value FROM wpm_setting WHERE setting_name = 'defaults'");
        $changed = \WpManager\Settings::changes(is_array($previous) ? \WpManager\Settings::fromJson((string)$previous['setting_value']) : [], $settings);
        $app->db->query(
            'REPLACE INTO wpm_setting (setting_name, setting_value) VALUES (?, ?)',
            'defaults',
            json_encode($settings, JSON_THROW_ON_ERROR),
        );
        if ($changed !== []) {
            wpm_audit($app, null, 'settings', '', implode('; ', $changed));
        }
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
    'auto_updates_options' => $options($yesNo, $config->get('updates.auto')),
    'notify_recipients' => $escape($config->get('notifications.recipients')),
    'notify_summary_options' => $options(
        ['off' => $wb['summary_off_txt'], 'daily' => $wb['summary_daily_txt'], 'weekly' => $wb['summary_weekly_txt']],
        $config->get('notifications.summary'),
    ),
    'notify_site_ready_options' => $options($yesNo, $config->get('notifications.site_ready')),
    'hd_quota' => $escape($d['hd_quota']),
    'traffic_quota' => $escape($d['traffic_quota']),
    'locale' => $escape($wp['locale']),
    'plugins' => $escape(implode(', ', $wp['plugins'])),
    'theme' => $escape($wp['theme']),
    'dry_run' => $config->isDryRun() ? 1 : 0,
]);

// Hardening of new sites: one checkbox per measure.
$chosen = (array)$config->get('hardening.new_sites');
$app->tpl->setLoop('hardening_measures', array_map(static fn(string $measure): array => [
    'measure' => $measure,
    'measure_label' => $escape($wb['hardening_' . $measure . '_txt'] ?? $measure),
    'measure_checked' => in_array($measure, $chosen, true) ? ' checked="checked"' : '',
], \WpManager\SiteHardening::MEASURES));
$feed = $app->db->queryOneRecord('SELECT imported, feed, records FROM wpm_vulnerability_feed ORDER BY batch DESC LIMIT 1');
$app->tpl->setVar('vuln_feed', is_array($feed) ? $escape($feed['imported'] . ' (' . $feed['feed'] . ', ' . $feed['records'] . ')') : '');

$csrf_token = $app->auth->csrf_token_get('wpm_settings');
$app->tpl->setVar('_csrf_id', $csrf_token['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrf_token['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
