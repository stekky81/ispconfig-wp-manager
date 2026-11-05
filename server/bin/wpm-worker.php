<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Node worker, run by cron every minute as root:
//   * * * * * root /usr/bin/php /opt/ispconfig-wp-manager/server/bin/wpm-worker.php
//
// Picks queued jobs, waits until ISPConfig has created the website on this node,
// installs WordPress and reports the result to the master.

declare(strict_types=1);

const WPM_MAX_WAIT = 3600;     // give up if the vhost does not appear within an hour
const WPM_MAX_ATTEMPTS = 3;    // failed installs are retried at most this many times
const WPM_SCAN_INTERVAL = 3600; // look for existing WordPress installs once an hour
const WPM_CHECK_INTERVAL = 86400; // read the updates of each site once a day
const WPM_CHECKS_PER_RUN = 3;   // keep each run short: jobs wait for the same lock
const WPM_AUTO_INTERVAL = 86400; // at most one automatic update of a site per day

if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
    fwrite(STDERR, "Run from cron as root.\n");
    exit(1);
}

// One worker at a time.
$lock = fopen('/run/ispconfig-wp-manager.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';

use WpManager\Config;
use WpManager\AutoUpdatePolicy;
use WpManager\Crypto;
use WpManager\HealthCheck;
use WpManager\JobQueue;
use WpManager\PathGuard;
use WpManager\RetryPolicy;
use WpManager\Settings;
use WpManager\SiteBackup;
use WpManager\SiteCloner;
use WpManager\SiteInventory;
use WpManager\SiteScanner;
use WpManager\SiteUpdater;
use WpManager\WordPressInstaller;

// ISPConfig server environment, for $app->dbmaster and $app->log.
require_once '/usr/local/ispconfig/server/lib/config.inc.php';
require_once '/usr/local/ispconfig/server/lib/app.inc.php';
/** @var app $app */

$config = Config::load();
$queue = new JobQueue($config->get('spool_dir'));
$installer = new WordPressInstaller(
    $config,
    Crypto::fromFile($config->get('key_file')),
    new PathGuard($config->get('web_root_base')),
);

$cloner = new SiteCloner($config, Crypto::fromFile($config->get('key_file')), new PathGuard($config->get('web_root_base')));
$backups = new SiteBackup($config, new PathGuard($config->get('web_root_base')));
$updater = new SiteUpdater($config, new PathGuard($config->get('web_root_base')), $backups, new HealthCheck());

$report = static function (int $siteId, string $status, string $message, bool $clearDbSecret = false, string $wpVersion = '') use ($app, $config): void {
    if ($config->isDryRun()) {
        $app->log("wpmanager dry-run: site {$siteId} → {$status}: {$message}", LOGLEVEL_DEBUG);
        return;
    }
    // Direct update (no datalog): status is informational and must not trigger the plugin again.
    $sql = 'UPDATE wpm_site SET status = ?, status_message = ?, wp_version = ?'
        . ($clearDbSecret ? ', secret_db = NULL' : '')
        . ' WHERE wpm_site_id = ?';
    $app->dbmaster->query($sql, $status, mb_substr($message, 0, 4000), $wpVersion, $siteId);
};

foreach ($queue->pending() as $name) {
    $job = $queue->read($name);
    $site = $job['data'];
    $siteId = (int)$job['site_id'];

    // Actions on existing sites run once: a failed restore must not start again by itself.
    if (in_array($job['type'], ['backup', 'restore', 'update'], true)) {
        $queue->remove($name);
        $record = $app->dbmaster->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', $siteId);
        if (!is_array($record) || $record['action_request'] !== $job['type'] || $config->isDryRun()) {
            continue;
        }
        $finish = static function (string $status, string $message) use ($app, $backups, $record, $siteId): void {
            $app->dbmaster->query(
                "UPDATE wpm_site SET action_request = '', action_param = '', action_status = ?, action_message = ?, backups_json = ? WHERE wpm_site_id = ?",
                $status,
                mb_substr($message, 0, 2000),
                json_encode($backups->list($record), JSON_THROW_ON_ERROR),
                $siteId,
            );
        };
        $app->dbmaster->query("UPDATE wpm_site SET action_status = 'running', action_message = '' WHERE wpm_site_id = ?", $siteId);
        try {
            if ($job['type'] === 'backup') {
                $finish('done', 'Backup ' . $backups->create($record) . ' created.');
            } elseif ($job['type'] === 'update') {
                $result = $updater->update($record, (string)$record['action_param']);
                $finish($result['outcome'], $result['message']);
                // Whatever happened, the versions on record may be out of date now.
                $app->dbmaster->query('UPDATE wpm_site SET last_check = NULL WHERE wpm_site_id = ?', $siteId);
            } else {
                $backups->restore($record, (string)$record['action_param']);
                $finish('done', 'Backup ' . $record['action_param'] . ' restored.');
            }
        } catch (\Throwable $e) {
            $app->log("wpmanager: {$job['type']} of {$record['domain']} failed: " . $e->getMessage(), LOGLEVEL_WARN);
            $finish('failed', $job['type'] . ' failed: ' . $e->getMessage());
        }
        continue;
    }

    if ($job['type'] !== 'install') {
        $queue->remove($name);
        continue;
    }

    if (!RetryPolicy::isDue($job, time())) {
        continue;
    }

    if (!$installer->isReady($site)) {
        if (time() - (int)$job['created'] > WPM_MAX_WAIT) {
            $report($siteId, 'error', 'The website was not created on this node within one hour.');
            $queue->remove($name);
        }
        continue;
    }

    $report($siteId, 'installing', '');
    try {
        if ((int)($site['clone_of'] ?? 0) > 0) {
            $origin = $app->dbmaster->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', $site['clone_of']);
            if (!is_array($origin)) {
                throw new \RuntimeException('Refusing to install: the site to clone no longer exists');
            }
            $log = $cloner->clone($site, $origin);
            $warnings = array_values(array_filter($log, static fn(string $line): bool => str_starts_with($line, 'Warning: ')));
            if ($warnings !== [] && !$config->isDryRun()) {
                $app->dbmaster->query(
                    "UPDATE wpm_site SET action_status = 'warning', action_message = ? WHERE wpm_site_id = ?",
                    implode(' ', $warnings),
                    $siteId,
                );
            }
        } else {
            $log = $installer->install($site);
        }
        $report($siteId, 'installed', implode("\n", $log), true, $installer->installedVersion($site));
        $queue->remove($name);
    } catch (\Throwable $e) {
        $job['attempts'] = (int)$job['attempts'] + 1;
        $app->log("wpmanager: install of {$site['domain']} failed: " . $e->getMessage(), LOGLEVEL_WARN);
        // "not empty" is never retried: something else is in that folder.
        if ($job['attempts'] >= WPM_MAX_ATTEMPTS || str_contains($e->getMessage(), 'Refusing to install')) {
            $report($siteId, 'error', $e->getMessage());
            $queue->remove($name);
        } else {
            $delay = RetryPolicy::delayAfter($job['attempts']);
            $job['not_before'] = time() + $delay;
            $report($siteId, 'queued', 'Attempt ' . $job['attempts'] . ' failed, next try in ' . intdiv($delay, 60) . ' minutes: ' . $e->getMessage());
            $queue->update($name, $job);
        }
    }
}

// Sites not created by this module. Read-only: at most `wp core version` as the site user.
$scanStamp = $config->get('spool_dir') . '/.last-scan';
if (!$config->isDryRun() && time() - (int)@filemtime($scanStamp) >= WPM_SCAN_INTERVAL) {
    touch($scanStamp);
    $guard = new PathGuard($config->get('web_root_base'));
    foreach ((new SiteScanner($guard, $config->get('web_root_base')))->scan() as $documentRoot) {
        try {
            $domain = $app->dbmaster->queryOneRecord(
                "SELECT * FROM web_domain WHERE document_root = ? AND server_id = ? AND type = 'vhost'",
                $documentRoot,
                $conf['server_id'],
            );
            if (!is_array($domain)) {
                continue;
            }
            $known = $app->dbmaster->queryOneRecord(
                'SELECT wpm_site_id FROM wpm_site WHERE domain_id = ? OR domain = ?',
                $domain['domain_id'],
                $domain['domain'],
            );
            if (is_array($known)) {
                continue;
            }
            $group = $app->dbmaster->queryOneRecord('SELECT client_id FROM sys_group WHERE groupid = ?', $domain['sys_groupid']);
            $record = SiteScanner::recordFor($domain, (int)($group['client_id'] ?? 0), $installer->installedVersion($domain));
            $columns = implode(', ', array_map(static fn(string $c): string => "`{$c}`", array_keys($record)));
            $marks = implode(', ', array_fill(0, count($record), '?'));
            $app->dbmaster->query("INSERT INTO wpm_site ({$columns}) VALUES ({$marks})", ...array_values($record));
        } catch (\Throwable $e) {
            $app->log("wpmanager: cannot register {$documentRoot}: " . $e->getMessage(), LOGLEVEL_WARN);
        }
    }
}

// Update overview. Read-only: no file changes, and normal plugins and themes do not load.
if (!$config->isDryRun()) {
    $inventory = new SiteInventory($config, new PathGuard($config->get('web_root_base')));
    $due = $app->dbmaster->queryAllRecords(
        "SELECT wpm_site_id, domain, document_root, system_user FROM wpm_site
         WHERE server_id = ? AND status = 'installed'
           AND (last_check IS NULL OR last_check < NOW() - INTERVAL " . WPM_CHECK_INTERVAL . ' SECOND)
         ORDER BY last_check IS NOT NULL, last_check LIMIT ' . WPM_CHECKS_PER_RUN,
        $conf['server_id'],
    );
    foreach ($due as $site) {
        try {
            $report = $inventory->collect($site);
            $app->dbmaster->query(
                'UPDATE wpm_site SET updates_json = ?, updates_count = ?, last_check = NOW(), wp_version = IF(? = \'\', wp_version, ?) WHERE wpm_site_id = ?',
                json_encode($report, JSON_THROW_ON_ERROR),
                SiteInventory::updateCount($report),
                $report['version'],
                $report['version'],
                $site['wpm_site_id'],
            );
        } catch (\Throwable $e) {
            $app->dbmaster->query(
                'UPDATE wpm_site SET updates_json = ?, updates_count = 0, last_check = NOW() WHERE wpm_site_id = ?',
                json_encode(['error' => mb_substr($e->getMessage(), 0, 500)]),
                $site['wpm_site_id'],
            );
        }
    }
}

// Automatic updates: plugins, themes and minor core releases, one site per run. dry_run stops
// them, then the switch in the settings on the master, then the one of the site.
if (!$config->isDryRun()) {
    $stored = $app->dbmaster->queryOneRecord("SELECT setting_value FROM wpm_setting WHERE setting_name = 'defaults'");
    $settings = is_array($stored) ? $config->withOverrides(Settings::fromJson((string)$stored['setting_value'])) : $config;
    $enabled = $settings->get('updates.auto') === 'y';

    $candidates = $enabled ? $app->dbmaster->queryAllRecords(
        "SELECT * FROM wpm_site
         WHERE server_id = ? AND status = 'installed' AND auto_update = 'y' AND updates_count > 0 AND action_request = ''
           AND (last_auto IS NULL OR last_auto < NOW() - INTERVAL " . WPM_AUTO_INTERVAL . ' SECOND)
         ORDER BY last_auto IS NOT NULL, last_auto',
        $conf['server_id'],
    ) : [];

    foreach ($candidates ?: [] as $site) {
        $report = json_decode((string)$site['updates_json'], true);
        if (!is_array($report) || !isset($report['plugins']) || !AutoUpdatePolicy::shouldRun($site, $report, $enabled)) {
            continue;
        }
        try {
            $result = $updater->update($site, SiteUpdater::AUTOMATIC);
        } catch (\Throwable $e) {
            $result = ['outcome' => 'failed', 'message' => 'Automatic update not started: ' . $e->getMessage()];
        }
        $rolledBack = in_array($result['outcome'], ['rolled_back', 'rollback_failed'], true);
        $app->dbmaster->query(
            'UPDATE wpm_site SET action_status = ?, action_message = ?, backups_json = ?, last_auto = NOW(), last_check = NULL,
                auto_skip = IF(?, ?, auto_skip) WHERE wpm_site_id = ?',
            $result['outcome'],
            'Automatic update: ' . mb_substr($result['message'], 0, 1900),
            json_encode($backups->list($site), JSON_THROW_ON_ERROR),
            $rolledBack ? 1 : 0,
            AutoUpdatePolicy::fingerprint($report),
            $site['wpm_site_id'],
        );
        break;
    }
}
