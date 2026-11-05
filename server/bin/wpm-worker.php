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
use WpManager\Crypto;
use WpManager\JobQueue;
use WpManager\PathGuard;
use WpManager\RetryPolicy;
use WpManager\SiteScanner;
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
        $log = $installer->install($site);
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
