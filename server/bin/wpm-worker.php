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

// Errors of PHP with date and time: the cron line only appends what it prints.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/var/log/ispconfig-wp-manager.log');

// One worker at a time. Close-on-exec ('e'): a command that outlives the worker must not keep the lock.
$lock = fopen('/run/ispconfig-wp-manager.lock', 'ce');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';

use WpManager\AuditLog;
use WpManager\Config;
use WpManager\AutoUpdatePolicy;
use WpManager\Crypto;
use WpManager\FailureMessage;
use WpManager\HealthCheck;
use WpManager\JobQueue;
use WpManager\MaintenanceMode;
use WpManager\Notifications;
use WpManager\PathGuard;
use WpManager\RetryPolicy;
use WpManager\Runner;
use WpManager\Settings;
use WpManager\SiteActions;
use WpManager\SiteBackup;
use WpManager\SiteCloner;
use WpManager\SiteCredentials;
use WpManager\SiteExtensions;
use WpManager\SiteHardening;
use WpManager\SitePermissions;
use WpManager\VulnerabilityFeed;
use WpManager\SiteInventory;
use WpManager\SiteRecord;
use WpManager\SiteRemoval;
use WpManager\SiteTransfer;
use WpManager\MysqlTransferStore;
use WpManager\TransferAuthorization;
use WpManager\SiteScanner;
use WpManager\Subfolder;
use WpManager\SiteSync;
use WpManager\SiteUpdater;
use WpManager\SiteUsers;
use WpManager\WpDebug;
use WpManager\WpCron;
use WpManager\WordPressInstaller;

// ISPConfig server environment, for $app->dbmaster and $app->log.
require_once '/usr/local/ispconfig/server/lib/config.inc.php';
require_once '/usr/local/ispconfig/server/lib/app.inc.php';
/** @var app $app */

ini_set('memory_limit', '512M');

$config = Config::loadTrusted();
$queue = new JobQueue($config->get('spool_dir'));
$installer = new WordPressInstaller(
    $config,
    Crypto::fromFile($config->get('key_file')),
    new PathGuard($config->get('web_root_base')),
);

$cloner = new SiteCloner($config, Crypto::fromFile($config->get('key_file')), new PathGuard($config->get('web_root_base')));
$backups = new SiteBackup($config, new PathGuard($config->get('web_root_base')));
$updater = new SiteUpdater($config, new PathGuard($config->get('web_root_base')), $backups, new HealthCheck());
$sync = new SiteSync($config, new PathGuard($config->get('web_root_base')), $backups, new HealthCheck());
$extensions = new SiteExtensions($config, new PathGuard($config->get('web_root_base')), $backups, new HealthCheck());
$maintenance = new MaintenanceMode($config, new PathGuard($config->get('web_root_base')));
$debug = new WpDebug($config, new PathGuard($config->get('web_root_base')));
$users = new SiteUsers($config, new PathGuard($config->get('web_root_base')));
$hardening = new SiteHardening($config, new PathGuard($config->get('web_root_base')), new HealthCheck());
$permissions = new SitePermissions($config, new PathGuard($config->get('web_root_base')), new HealthCheck());
// A password for the panel, shown there once: only the panel and the nodes have the key.
$seal = static fn(array $result): string => Crypto::fromFile($config->get('key_file'))->encrypt(json_encode($result, JSON_THROW_ON_ERROR));

// A site owner can make this folder unreadable: that must not stop the worker for every site.
$listBackups = static function (array $site) use ($backups): array {
    try {
        return $backups->list($site);
    } catch (\RuntimeException) {
        return [];
    }
};

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

// The settings saved in the panel, on the master.
$stored = $app->dbmaster->queryOneRecord("SELECT setting_value FROM wpm_setting WHERE setting_name = 'defaults'");
$settings = is_array($stored) ? $config->withOverrides(Settings::fromJson((string)$stored['setting_value'])) : $config;

// Emails go out with the mail settings of ISPConfig, read from the master: the copy of a node can be old.
// A failed email is logged and never stops the worker.
$mailConfig = null;
$sendMail = static function (array $mail) use ($app, $config, $settings, &$mailConfig): void {
    if ($config->isDryRun()) {
        return;
    }
    try {
        if ($mailConfig === null) {
            $app->uses('ini_parser,ispcmail');
            $row = $app->dbmaster->queryOneRecord('SELECT config FROM sys_ini WHERE sysini_id = 1');
            $ini = $app->ini_parser->parse_ini_string(stripslashes((string)($row['config'] ?? '')));
            $mailConfig = is_array($ini['mail'] ?? null) ? $ini['mail'] : [];
        }
        $admin = (string)($mailConfig['admin_mail'] ?? '');
        $to = Notifications::recipients((string)$settings->get('notifications.recipients'), $admin);
        if ($to === []) {
            return;
        }
        $from = filter_var($admin, FILTER_VALIDATE_EMAIL) !== false ? $admin : 'wpmanager@' . php_uname('n');
        $options = $mailConfig;
        if (($mailConfig['smtp_enabled'] ?? '') === 'y') {
            $options['use_smtp'] = true;
        }
        foreach ($to as $address) {
            $app->ispcmail->setOptions($options);
            $app->ispcmail->setSender($from, (string)($mailConfig['admin_name'] ?? '') ?: 'WordPress Manager');
            $app->ispcmail->setSubject($mail['subject']);
            $app->ispcmail->setMailText($mail['body']);
            $sent = $app->ispcmail->send($address);
            $app->ispcmail->finish();
            if (!$sent) {
                error_log("wpmanager: email to {$address} not sent: {$mail['subject']}");
            }
        }
    } catch (\Throwable $e) {
        error_log('wpmanager: email not sent: ' . $e->getMessage());
    }
};

// What happened, for the summary; rollbacks and failures are sent at once.
$event = static function (array $site, string $kind, string $outcome, string $message, bool $alert = true) use ($app, $conf, $config, $sendMail): void {
    if ($config->isDryRun()) {
        return;
    }
    try {
        $app->dbmaster->query(
            'INSERT INTO wpm_event (wpm_site_id, server_id, domain, kind, outcome, message) VALUES (?, ?, ?, ?, ?, ?)',
            (int)$site['wpm_site_id'],
            (int)$conf['server_id'],
            Subfolder::label($site),
            $kind,
            $outcome,
            mb_substr($message, 0, 1000),
        );
    } catch (\Throwable $e) {
        error_log('wpmanager: event not recorded: ' . $e->getMessage());
    }
    if ($alert && Notifications::isUrgent($outcome)) {
        $sendMail(Notifications::alert($site, $kind, $outcome, $message));
    }
};

// Every node can write wpm_site: before acting on a row, check it against its website in ISPConfig.
$distrust = static function (array $site, ?int $serverId = null) use ($app, $conf): string {
    $web = $app->dbmaster->queryOneRecord(
        'SELECT domain_id, server_id, domain, document_root, system_user FROM web_domain WHERE domain_id = ?',
        (int)($site['domain_id'] ?? 0),
    );
    // One record per website and folder: sites in subfolders share the website of the site in web/.
    $count = $app->dbmaster->queryOneRecord(
        'SELECT COUNT(*) AS n FROM wpm_site WHERE domain_id = ? AND subfolder = ?',
        (int)($site['domain_id'] ?? 0),
        (string)($site['subfolder'] ?? ''),
    );
    $reason = SiteRecord::mismatch($site, is_array($web) ? $web : null, $serverId ?? (int)$conf['server_id'], (int)($count['n'] ?? 1));
    if ($reason !== '') {
        error_log("wpmanager: site {$site['domain']} refused: {$reason}");
    }
    return $reason;
};

// Known vulnerabilities of one site, from its report and the rows of the latest feed. New ones are sent at once,
// one email per site, with the links and the copyright notice the data asks for.
$matchVulnerabilities = static function (array $site) use ($app, $sendMail, $event, $settings): void {
    $report = json_decode((string)($site['updates_json'] ?? ''), true);
    $feed = $app->dbmaster->queryOneRecord('SELECT batch, copyrights FROM wpm_vulnerability_feed ORDER BY batch DESC LIMIT 1');
    if (!is_array($report) || !isset($report['plugins']) || !is_array($feed)) {
        return;
    }
    $conditions = [];
    $values = [(int)$feed['batch']];
    foreach (VulnerabilityFeed::softwareOf($report) as $software) {
        $conditions[] = '(type = ? AND slug = ?)';
        array_push($values, $software['type'], $software['slug']);
    }
    $rows = $app->dbmaster->queryAllRecords(
        'SELECT vuln_id, type, slug, title, cvss_score, cvss_rating, link, ranges, patched FROM wpm_vulnerability WHERE batch = ? AND (' . implode(' OR ', $conditions) . ')',
        ...$values,
    ) ?: [];
    $found = VulnerabilityFeed::forSite($report, $rows);
    $fixable = count(array_filter($found, static fn(array $v): bool => $v['fixed_in'] !== ''));
    $app->dbmaster->query(
        'UPDATE wpm_site SET vulns_json = ?, vulns_count = ?, vulns_fixable = ? WHERE wpm_site_id = ?',
        json_encode($found, JSON_THROW_ON_ERROR),
        count($found),
        $fixable,
        $site['wpm_site_id'],
    );
    $new = [];
    foreach ($found as $vulnerability) {
        $app->dbmaster->query('INSERT IGNORE INTO wpm_notice (notice) VALUES (?)', 'vuln-' . (int)$site['wpm_site_id'] . '-' . substr(sha1($vulnerability['id'] . '/' . $vulnerability['slug']), 0, 32));
        if ($app->dbmaster->affectedRows() === 1) {
            $new[] = $vulnerability;
        }
    }
    if ($new !== []) {
        $attribution = VulnerabilityFeed::attribution(json_decode((string)$feed['copyrights'], true) ?: []);
        $automatic = $settings->get('updates.auto') === 'y' && ($site['auto_update'] ?? 'n') === 'y';
        $sendMail(Notifications::vulnerabilities($site, $new, $attribution, $automatic));
        $event($site, 'vulnerability', 'found', count($new) . ' new: ' . implode(', ', array_map(static fn(array $v): string => "{$v['slug']} {$v['version']}", $new)), false);
    }
};

// Clones between nodes travel through the master database, with a connection of their own.
$transfers = null;
$transferStore = static function () use (&$transfers, $conf): MysqlTransferStore {
    // On the master itself ISPConfig leaves dbmaster_* empty and uses its local connection.
    $prefix = (string)($conf['dbmaster_host'] ?? '') !== '' ? 'dbmaster_' : 'db_';
    return $transfers ??= new MysqlTransferStore(
        (string)$conf[$prefix . 'host'],
        (string)$conf[$prefix . 'user'],
        (string)$conf[$prefix . 'password'],
        (string)$conf[$prefix . 'database'],
        (int)($conf[$prefix . 'port'] ?? 3306),
    );
};

// A node of a clone asks for a site of this node: sent only if the panel asked for that clone.
if (!$config->isDryRun()) {
    try {
        $store = $transferStore();
        $store->prune(86400);
        $claim = $store->claim((int)$conf['server_id']);
    } catch (\Throwable $e) {
        error_log('wpmanager: transfers not checked: ' . $e->getMessage());
        $claim = null;
    }
    if ($claim !== null) {
        $out = new SiteTransfer($store, Crypto::fromFile($config->get('key_file')), $claim['transfer_id']);
        $source = $app->dbmaster->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', $claim['source_site_id']);
        try {
            $target = $app->dbmaster->queryOneRecord('SELECT wpm_site_id, server_id FROM wpm_site WHERE wpm_site_id = ?', $claim['target_site_id']);
            $request = $app->dbmaster->queryOneRecord(
                "SELECT data FROM sys_datalog WHERE dbtable = 'wpm_site' AND dbidx = ? AND action = 'i' ORDER BY datalog_id DESC",
                'wpm_site_id:' . $claim['target_site_id'],
            );
            $refusal = !is_array($source) || !is_array($target) ? 'a site of this transfer no longer exists'
                : TransferAuthorization::refusal(is_array($request) ? (string)$request['data'] : null, (int)$source['wpm_site_id'], (int)$target['wpm_site_id'], (int)$target['server_id']);
            if ($refusal === '' && ($reason = $distrust($source)) !== '') {
                $refusal = "the record of {$source['domain']} cannot be trusted, {$reason}";
            }
            if ($refusal !== '') {
                error_log("wpmanager: transfer {$claim['transfer_id']} refused: {$refusal}");
                throw new \RuntimeException("Refused: {$refusal}");
            }
            $cloner->export($source, $out);
        } catch (\Throwable $e) {
            $out->fail(FailureMessage::step('Sending ' . (is_array($source) ? $source['domain'] : 'the site'), $e->getMessage()));
        }
    }
}

foreach ($queue->pending() as $name) {
    $job = $queue->read($name);
    $site = $job['data'];
    $siteId = (int)$job['site_id'];

    // Actions on existing sites run once: a failed restore must not start again by itself.
    if (in_array($job['type'], SiteActions::ALL, true)) {
        $queue->remove($name);
        $record = $app->dbmaster->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', $siteId);
        if (!is_array($record) || $record['action_request'] !== $job['type'] || $config->isDryRun()) {
            continue;
        }
        $finish = static function (string $status, string $message) use ($app, $listBackups, $record, $siteId, $event, $job): void {
            $app->dbmaster->query(
                "UPDATE wpm_site SET action_request = '', action_param = '', action_status = ?, action_message = ?, backups_json = ? WHERE wpm_site_id = ?",
                $status,
                mb_substr($message, 0, 2000),
                json_encode($listBackups($record), JSON_THROW_ON_ERROR),
                $siteId,
            );
            $event($record, (string)$job['type'], $status, $message);
        };
        $app->dbmaster->query("UPDATE wpm_site SET action_status = 'running', action_message = '' WHERE wpm_site_id = ?", $siteId);
        try {
            $reason = $distrust($record);
            if ($reason !== '') {
                throw new \RuntimeException("Refused: the record of {$record['domain']} cannot be trusted, {$reason}");
            }
            if ($job['type'] === 'backup') {
                $finish('done', 'Backup ' . $backups->create($record) . ' created.');
            } elseif ($job['type'] === 'wpcron') {
                (new WpCron($config, new PathGuard($config->get('web_root_base'))))->apply($record, (string)$record['action_param']);
                $app->dbmaster->query('UPDATE wpm_site SET wp_cron = ? WHERE wpm_site_id = ?', $record['action_param'], $siteId);
                $finish('done', 'WordPress cron mode: ' . $record['action_param'] . '.');
            } elseif ($job['type'] === 'refresh' || $job['type'] === 'push') {
                $production = $app->dbmaster->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', (int)$record['staging_of']);
                if (!is_array($production) || (int)$record['staging_of'] === 0) {
                    throw new \RuntimeException('The production site of this staging no longer exists');
                }
                $reason = $distrust($production);
                if ($reason !== '') {
                    throw new \RuntimeException("Refused: the record of {$production['domain']} cannot be trusted, {$reason}");
                }
                if ($job['type'] === 'push') {
                    $app->dbmaster->query("UPDATE wpm_site SET action_status = 'running', action_message = ? WHERE wpm_site_id = ?", 'Push from ' . $record['domain'], $production['wpm_site_id']);
                    $result = $sync->push($record, $production);
                    $app->dbmaster->query(
                        'UPDATE wpm_site SET action_status = ?, action_message = ?, backups_json = ?, last_check = NULL WHERE wpm_site_id = ?',
                        $result['outcome'],
                        mb_substr($result['message'], 0, 2000),
                        json_encode($listBackups($production), JSON_THROW_ON_ERROR),
                        $production['wpm_site_id'],
                    );
                } else {
                    $result = $sync->refresh($record, $production);
                }
                $finish($result['outcome'], $result['message']);
                $app->dbmaster->query('UPDATE wpm_site SET last_check = NULL WHERE wpm_site_id = ?', $siteId);
            } elseif ($job['type'] === 'maintenance') {
                $maintenance->set($record, $record['action_param'] === 'on');
                $finish('done', $record['action_param'] === 'on' ? 'Maintenance mode on.' : 'Maintenance mode off.');
                $app->dbmaster->query('UPDATE wpm_site SET last_check = NULL WHERE wpm_site_id = ?', $siteId);
            } elseif ($job['type'] === 'debug') {
                $on = $record['action_param'] === 'on';
                $debug->set($record, $on);
                $app->dbmaster->query(
                    'UPDATE wpm_site SET debug_until = IF(?, NOW() + INTERVAL ' . WpDebug::HOURS . ' HOUR, NULL), last_check = NULL WHERE wpm_site_id = ?',
                    $on ? 1 : 0,
                    $siteId,
                );
                $finish('done', $on ? 'Debug mode on for ' . WpDebug::HOURS . ' hours, log in ' . WpDebug::log($record) . '.' : 'Debug mode off.');
            } elseif ($job['type'] === 'debuglog') {
                $tail = $debug->tail($record);
                $app->dbmaster->query('UPDATE wpm_site SET debug_tail = ? WHERE wpm_site_id = ?', $tail, $siteId);
                $finish('done', $tail === '' ? 'The debug log is empty or does not exist yet.' : 'Last lines of the debug log read.');
            } elseif ($job['type'] === 'login') {
                $login = $users->createLogin($record, SiteUsers::isTemporary((string)$record['login_user']) ? (string)$record['login_user'] : '');
                $app->dbmaster->query(
                    "UPDATE wpm_site SET login_user = ?, login_until = NOW() + INTERVAL " . SiteUsers::UNUSED_MINUTES . " MINUTE, login_used = 'n',
                        secret_result = ?, secret_until = NOW() + INTERVAL " . SiteUsers::UNUSED_MINUTES . ' MINUTE WHERE wpm_site_id = ?',
                    $login['user'],
                    $seal(['kind' => 'login'] + $login),
                    $siteId,
                );
                $finish('done', "Temporary administrator {$login['user']} created.");
            } elseif ($job['type'] === 'password') {
                $reset = $users->resetPassword($record, (int)$record['action_param']);
                $app->dbmaster->query(
                    'UPDATE wpm_site SET secret_result = ?, secret_until = NOW() + INTERVAL 1 DAY WHERE wpm_site_id = ?',
                    $seal(['kind' => 'password'] + $reset),
                    $siteId,
                );
                $finish('done', "New password of {$reset['user']}; its sessions are closed.");
            } elseif ($job['type'] === 'harden') {
                [$measure, $state] = explode(':', (string)$record['action_param']) + ['', ''];
                if (!in_array($state, ['on', 'off'], true)) {
                    throw new \RuntimeException("Invalid hardening request: {$record['action_param']}");
                }
                $result = $hardening->set($record, $measure, $state === 'on');
                $finish($result['outcome'], $result['message']);
                $app->dbmaster->query('UPDATE wpm_site SET last_check = NULL WHERE wpm_site_id = ?', $siteId);
            } elseif ($job['type'] === 'remove_folder') {
                // The panel deleted the database already. Only web/<folder> of the website goes, with its backups.
                $runner = new Runner($config->isDryRun(), [], 3600);
                foreach (SiteRemoval::folderCommands($record, new PathGuard($config->get('web_root_base')), $backups) as $command) {
                    $runner->mustRun($command);
                }
                $finish('done', 'Folder web/' . Subfolder::of($record) . ' removed.');
                $app->dbmaster->query("UPDATE wpm_site SET status = 'removed' WHERE wpm_site_id = ?", $siteId);
            } elseif ($job['type'] === 'fix_permissions') {
                $result = $permissions->fix($record);
                $finish($result['outcome'], $result['message']);
                $app->dbmaster->query('UPDATE wpm_site SET last_check = NULL WHERE wpm_site_id = ?', $siteId);
            } elseif (in_array($job['type'], SiteExtensions::ACTIONS, true)) {
                $result = $extensions->run($record, (string)$job['type'], (string)$record['action_param']);
                $finish($result['outcome'], $result['message']);
                $app->dbmaster->query('UPDATE wpm_site SET last_check = NULL WHERE wpm_site_id = ?', $siteId);
            } elseif ($job['type'] === 'update') {
                $result = $updater->update($record, (string)$record['action_param']);
                $finish($result['outcome'], $result['message']);
                // Whatever happened, the versions on record may be out of date now.
                $app->dbmaster->query('UPDATE wpm_site SET last_check = NULL WHERE wpm_site_id = ?', $siteId);
            } else {
                $backups->restore($record, (string)$record['action_param']);
                $finish('done', 'Backup ' . $record['action_param'] . ' restored.');
                $app->dbmaster->query('UPDATE wpm_site SET last_check = NULL WHERE wpm_site_id = ?', $siteId);
            }
        } catch (\Throwable $e) {
            $app->log("wpmanager: {$job['type']} of {$record['domain']} failed: " . $e->getMessage(), LOGLEVEL_WARN);
            $finish('failed', FailureMessage::step(ucfirst($job['type']), $e->getMessage()));
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
            $report($siteId, 'error', 'The website was not created on this node within one hour.', true);
            $queue->remove($name);
        }
        continue;
    }

    $report($siteId, 'installing', '');
    // Only this job may write over what it wrote itself in an earlier attempt: the flag lives in the
    // spool, which only root can change, and is set when an attempt found the web root empty and left
    // it not empty.
    $resume = (bool)($job['resume'] ?? false);
    $isEmpty = static function () use ($installer, $site): bool {
        try {
            return $installer->isTargetEmpty($site);
        } catch (\Throwable) {
            return false;
        }
    };
    $wasEmpty = $isEmpty();
    try {
        // Not retried: the record will not change by itself.
        $reason = $distrust($site);
        if ($reason !== '') {
            throw new \RuntimeException("Refusing to install: the record of {$site['domain']} cannot be trusted, {$reason}");
        }
        if ((int)($site['clone_of'] ?? 0) > 0) {
            $origin = $app->dbmaster->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', $site['clone_of']);
            if (!is_array($origin)) {
                throw new \RuntimeException('Refusing to install: the site to clone no longer exists');
            }
            $reason = $distrust($origin, (int)$origin['server_id']);
            if ($reason !== '') {
                throw new \RuntimeException("Refusing to install: the record of {$origin['domain']} cannot be trusted, {$reason}");
            }
            if ((int)$origin['server_id'] === (int)$conf['server_id']) {
                $log = $cloner->clone($site, $origin, null, $resume);
            } else {
                // The node of the source sends it; this worker waits for its pieces.
                $store = $transferStore();
                $transferId = $store->open((int)$origin['wpm_site_id'], $siteId, (int)$origin['server_id'], (int)$conf['server_id']);
                try {
                    $log = $cloner->clone($site, $origin, null, $resume, new SiteTransfer($store, Crypto::fromFile($config->get('key_file')), $transferId));
                } catch (\Throwable $e) {
                    $store->setState($transferId, 'failed', $e->getMessage());
                    throw $e;
                } finally {
                    $store->close($transferId);
                }
            }
        } else {
            $log = $installer->install($site, null, $resume);
        }
        $warnings = array_values(array_filter($log, static fn(string $line): bool => str_starts_with($line, 'Warning: ')));
        if (!$config->isDryRun()) {
            // The measures of the settings; one that does not work here is a warning, the site works without it.
            $warnings = array_merge($warnings, $hardening->applyDefaults($site + ['wpm_site_id' => $siteId], (array)$settings->get('hardening.new_sites')));
            $health = (new HealthCheck())->warningAfterInstall($site);
            if ($health !== '') {
                $warnings[] = $health;
            }
        }
        if ($warnings !== [] && !$config->isDryRun()) {
            $app->dbmaster->query(
                "UPDATE wpm_site SET action_status = 'warning', action_message = ? WHERE wpm_site_id = ?",
                mb_substr(implode(' ', $warnings), 0, 2000),
                $siteId,
            );
        }
        $report($siteId, 'installed', implode("\n", $log), true, $installer->installedVersion($site));
        $queue->remove($name);
        $outcome = $warnings === [] ? 'installed' : 'warning';
        $kind = (int)($site['clone_of'] ?? 0) > 0 ? 'clone' : 'install';
        $event($site + ['wpm_site_id' => $siteId], $kind, $outcome, implode(' ', $warnings));
        if ($settings->get('notifications.site_ready') === 'y') {
            $sendMail(Notifications::installed($site, $outcome, implode("\n", $warnings)));
        }
    } catch (\Throwable $e) {
        $job['attempts'] = (int)$job['attempts'] + 1;
        $message = FailureMessage::explain($e->getMessage());
        $app->log("wpmanager: install of {$site['domain']} failed: " . $message, LOGLEVEL_WARN);
        if ($wasEmpty && !$isEmpty()) {
            $job['resume'] = true;
        }
        // "not empty" is never retried: something else is in that folder.
        if ($job['attempts'] >= WPM_MAX_ATTEMPTS || str_contains($e->getMessage(), 'Refusing to install')) {
            // Final: nothing will use the database password again.
            $report($siteId, 'error', $message, true);
            $queue->remove($name);
            $event($site + ['wpm_site_id' => $siteId], (int)($site['clone_of'] ?? 0) > 0 ? 'clone' : 'install', 'error', $message, false);
            $sendMail(Notifications::installed($site, 'error', $message));
        } else {
            $delay = RetryPolicy::delayAfter($job['attempts']);
            $job['not_before'] = time() + $delay;
            $report($siteId, 'queued', 'Attempt ' . $job['attempts'] . ' failed, next try in ' . intdiv($delay, 60) . ' minutes: ' . $message);
            $queue->update($name, $job);
        }
    }
}

// One-time passwords nobody looked at: the clock starts when the site is ready, and after
// SiteCredentials::EXPIRY_DAYS they are deleted. Direct updates, like the database password above.
if (!$config->isDryRun()) {
    $stored = $app->dbmaster->queryAllRecords(
        "SELECT wpm_site_id, status, secret_access, secret_wp, UNIX_TIMESTAMP(secret_since) AS secret_since, UNIX_TIMESTAMP() AS now FROM wpm_site
         WHERE server_id = ? AND ((secret_access IS NOT NULL AND secret_access != '') OR (secret_wp IS NOT NULL AND secret_wp != ''))",
        $conf['server_id'],
    );
    foreach ($stored ?: [] as $site) {
        // The clock of the master, which wrote secret_since: a node may run ahead or behind it.
        $step = SiteCredentials::housekeeping($site, (int)$site['now']);
        if ($step === SiteCredentials::START_CLOCK) {
            $app->dbmaster->query('UPDATE wpm_site SET secret_since = NOW() WHERE wpm_site_id = ? AND secret_since IS NULL', $site['wpm_site_id']);
        } elseif ($step === SiteCredentials::EXPIRE) {
            $app->dbmaster->query('UPDATE wpm_site SET secret_wp = NULL, secret_access = NULL WHERE wpm_site_id = ?', $site['wpm_site_id']);
            $app->log('wpmanager: one-time passwords of site ' . (int)$site['wpm_site_id'] . ' expired', LOGLEVEL_DEBUG);
        }
    }
}

// Maintenance tools: temporary logins, debug modes and passwords for the panel that ran out of time.
if (!$config->isDryRun()) {
    $app->dbmaster->query(
        'UPDATE wpm_site SET secret_result = NULL, secret_until = NULL WHERE server_id = ? AND secret_until < NOW()',
        $conf['server_id'],
    );
    $logins = $app->dbmaster->queryAllRecords(
        "SELECT *, login_until <= NOW() AS expired FROM wpm_site WHERE server_id = ? AND login_user != ''",
        $conf['server_id'],
    );
    foreach ($logins ?: [] as $site) {
        try {
            if ($distrust($site) !== '') {
                continue;
            }
            if (!SiteUsers::isTemporary((string)$site['login_user'])) {
                // Never deleted: only the users of the one-click login are.
                error_log("wpmanager: {$site['domain']}: {$site['login_user']} is not a temporary login, left alone");
                $app->dbmaster->query("UPDATE wpm_site SET login_user = '', login_until = NULL, login_used = 'n' WHERE wpm_site_id = ?", $site['wpm_site_id']);
                continue;
            }
            if ((int)$site['expired'] === 1) {
                $done = $users->removeLogin($site, (string)$site['login_user']);
                $app->dbmaster->query(
                    "UPDATE wpm_site SET login_user = '', login_until = NULL, login_used = 'n' WHERE wpm_site_id = ? AND login_user = ?",
                    $site['wpm_site_id'],
                    $site['login_user'],
                );
                $event($site, 'login_expired', 'done', "Temporary administrator: {$done}.");
            } elseif ($site['login_used'] !== 'y' && $users->hasSession($site, (string)$site['login_user'])) {
                // Logged in: it lives for the work in wp-admin, from now.
                $app->dbmaster->query(
                    "UPDATE wpm_site SET login_used = 'y', login_until = NOW() + INTERVAL " . SiteUsers::USED_MINUTES . ' MINUTE WHERE wpm_site_id = ? AND login_user = ?',
                    $site['wpm_site_id'],
                    $site['login_user'],
                );
            }
        } catch (\Throwable $e) {
            error_log("wpmanager: temporary login of {$site['domain']}: " . $e->getMessage());
        }
    }
    $debugging = $app->dbmaster->queryAllRecords(
        'SELECT * FROM wpm_site WHERE server_id = ? AND debug_until IS NOT NULL AND debug_until <= NOW()',
        $conf['server_id'],
    );
    foreach ($debugging ?: [] as $site) {
        try {
            if ($distrust($site) !== '') {
                continue;
            }
            $debug->set($site, false);
            $app->dbmaster->query('UPDATE wpm_site SET debug_until = NULL, last_check = NULL WHERE wpm_site_id = ?', $site['wpm_site_id']);
            $event($site, 'debug_expired', 'done', 'Debug mode turned off after ' . WpDebug::HOURS . ' hours.');
        } catch (\Throwable $e) {
            error_log("wpmanager: debug mode of {$site['domain']} not turned off: " . $e->getMessage());
        }
    }
}

// Sites not created by this module. Read-only: at most `wp core version` as the site user.
$scanStamp = $config->get('spool_dir') . '/.last-scan';
if (!$config->isDryRun() && time() - (int)@filemtime($scanStamp) >= WPM_SCAN_INTERVAL) {
    touch($scanStamp);
    $guard = new PathGuard($config->get('web_root_base'));
    $scanner = new SiteScanner($guard, $config->get('web_root_base'));
    $installs = array_merge(
        array_map(static fn(string $root): array => [$root, ''], $scanner->scan()),
        $scanner->scanSubfolders(),
    );
    foreach ($installs as [$documentRoot, $subfolder]) {
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
                'SELECT wpm_site_id FROM wpm_site WHERE (domain_id = ? OR domain = ?) AND subfolder = ?',
                $domain['domain_id'],
                $domain['domain'],
                $subfolder,
            );
            if (is_array($known)) {
                continue;
            }
            $group = $app->dbmaster->queryOneRecord('SELECT client_id FROM sys_group WHERE groupid = ?', $domain['sys_groupid']);
            $version = $installer->installedVersion($domain + ['subfolder' => $subfolder]);
            $record = SiteScanner::recordFor($domain, (int)($group['client_id'] ?? 0), $version, $subfolder);
            $columns = implode(', ', array_map(static fn(string $c): string => "`{$c}`", array_keys($record)));
            $marks = implode(', ', array_fill(0, count($record), '?'));
            $app->dbmaster->query("INSERT INTO wpm_site ({$columns}) VALUES ({$marks})", ...array_values($record));
        } catch (\Throwable $e) {
            $app->log("wpmanager: cannot register {$documentRoot}/web/{$subfolder}: " . $e->getMessage(), LOGLEVEL_WARN);
        }
    }
}

// Update overview. Read-only: no file changes, and normal plugins and themes do not load.
if (!$config->isDryRun()) {
    $inventory = new SiteInventory($config, new PathGuard($config->get('web_root_base')));
    $due = $app->dbmaster->queryAllRecords(
        "SELECT s.wpm_site_id, s.domain_id, s.server_id, s.domain, s.subfolder, s.document_root, s.system_user FROM wpm_site s
         INNER JOIN web_domain d ON d.domain_id = s.domain_id AND d.active = 'y'
         WHERE s.server_id = ? AND s.status = 'installed'
           AND (s.last_check IS NULL OR s.last_check < NOW() - INTERVAL " . WPM_CHECK_INTERVAL . ' SECOND)
         ORDER BY s.last_check IS NOT NULL, s.last_check LIMIT ' . WPM_CHECKS_PER_RUN,
        $conf['server_id'],
    );
    foreach ($due as $site) {
        $reason = $distrust($site);
        if ($reason !== '') {
            // Checked, so that it does not take the turn of the other sites at every run.
            $app->dbmaster->query(
                'UPDATE wpm_site SET updates_json = ?, updates_count = 0, last_check = NOW() WHERE wpm_site_id = ?',
                json_encode(['error' => 'Not checked: the record cannot be trusted, ' . $reason]),
                $site['wpm_site_id'],
            );
            continue;
        }
        try {
            $report = $inventory->collect($site);
            $app->dbmaster->query(
                'UPDATE wpm_site SET updates_json = ?, updates_count = ?, last_check = NOW(), wp_version = IF(? = \'\', wp_version, ?), wp_cron = ? WHERE wpm_site_id = ?',
                json_encode($report, JSON_THROW_ON_ERROR),
                SiteInventory::updateCount($report),
                $report['version'],
                $report['version'],
                $report['wp_cron_disabled'] ? WpCron::REAL : WpCron::WORDPRESS,
                $site['wpm_site_id'],
            );
            $fresh = $app->dbmaster->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', $site['wpm_site_id']);
            if (is_array($fresh)) {
                $matchVulnerabilities($fresh);
            }
        } catch (\Throwable $e) {
            $app->dbmaster->query(
                'UPDATE wpm_site SET updates_json = ?, updates_count = 0, last_check = NOW() WHERE wpm_site_id = ?',
                json_encode(['error' => mb_substr($e->getMessage(), 0, 500)]),
                $site['wpm_site_id'],
            );
        }
    }
}

// The vulnerability feed of Wordfence Intelligence: once a day, by the first node with a key that claims the slot.
// Wordfence does not publish its limits: at most one try every 6 hours, four a day, until one works.
$wordfenceKey = (string)$config->get('vulnerabilities.wordfence_key');
if (!$config->isDryRun() && $wordfenceKey !== '') {
    $latest = $app->dbmaster->queryOneRecord('SELECT UNIX_TIMESTAMP(imported) AS at FROM wpm_vulnerability_feed ORDER BY batch DESC LIMIT 1');
    $now = (int)($app->dbmaster->queryOneRecord('SELECT UNIX_TIMESTAMP() AS now')['now'] ?? time());
    if (!is_array($latest) || $now - (int)$latest['at'] >= 86400) {
        $app->dbmaster->query('INSERT IGNORE INTO wpm_notice (notice) VALUES (?)', 'vulnfeed-' . date('Y-m-d', $now) . '-' . intdiv((int)date('G', $now), 6));
        if ($app->dbmaster->affectedRows() === 1) {
            $file = $config->get('spool_dir') . '/.wordfence-feed.json';
            $feedName = (string)$config->get('vulnerabilities.feed');
            try {
                VulnerabilityFeed::download($wordfenceKey, $feedName, $file);
                // The whole feed is one JSON document of some hundred MB once decoded.
                ini_set('memory_limit', '2048M');
                $decoded = json_decode((string)file_get_contents($file), true, 64);
                @unlink($file);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('the feed is not JSON');
                }
                $parsed = VulnerabilityFeed::rows($decoded);
                unset($decoded);
                foreach (array_chunk($parsed['rows'], 500) as $chunk) {
                    $values = [];
                    foreach ($chunk as $row) {
                        array_push($values, $now, ...array_values($row));
                    }
                    $app->dbmaster->query(
                        'INSERT INTO wpm_vulnerability (batch, vuln_id, type, slug, title, cvss_score, cvss_rating, link, ranges, patched) VALUES '
                        . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                        ...$values,
                    );
                }
                $app->dbmaster->query(
                    'INSERT INTO wpm_vulnerability_feed (batch, feed, records, copyrights) VALUES (?, ?, ?, ?)',
                    $now,
                    $feedName,
                    count($parsed['rows']),
                    json_encode($parsed['copyrights'], JSON_THROW_ON_ERROR),
                );
                $app->dbmaster->query('DELETE FROM wpm_vulnerability WHERE batch != ?', $now);
                $app->dbmaster->query('DELETE FROM wpm_vulnerability_feed WHERE batch != ?', $now);
                error_log('wpmanager: vulnerability feed imported, ' . count($parsed['rows']) . ' rows');
                unset($parsed);
            } catch (\Throwable $e) {
                @unlink($file);
                $app->dbmaster->query('DELETE FROM wpm_vulnerability WHERE batch = ?', $now);
                error_log('wpmanager: vulnerability feed not imported: ' . $e->getMessage());
            }
            ini_set('memory_limit', '512M');
        }
    }
}

// A new feed: every site of this node is matched again, without waiting for its daily check.
if (!$config->isDryRun()) {
    $feedStamp = $config->get('spool_dir') . '/.vulnerability-batch';
    $batch = (int)($app->dbmaster->queryOneRecord('SELECT MAX(batch) AS batch FROM wpm_vulnerability_feed')['batch'] ?? 0);
    if ($batch > 0 && $batch !== (int)@file_get_contents($feedStamp)) {
        foreach ($app->dbmaster->queryAllRecords("SELECT * FROM wpm_site WHERE server_id = ? AND status = 'installed'", $conf['server_id']) ?: [] as $site) {
            try {
                if ($distrust($site) === '') {
                    $matchVulnerabilities($site);
                }
            } catch (\Throwable $e) {
                error_log("wpmanager: vulnerabilities of {$site['domain']} not matched: " . $e->getMessage());
            }
        }
        file_put_contents($feedStamp, (string)$batch);
    }
}

// Automatic updates: plugins, themes and minor core releases, one site per run. dry_run stops
// them, then the switch in the settings on the master, then the one of the site.
if (!$config->isDryRun()) {
    $enabled = $settings->get('updates.auto') === 'y';

    $candidates = $enabled ? $app->dbmaster->queryAllRecords(
        "SELECT s.* FROM wpm_site s
         INNER JOIN web_domain d ON d.domain_id = s.domain_id AND d.active = 'y'
         WHERE s.server_id = ? AND s.status = 'installed' AND s.auto_update = 'y' AND s.updates_count > 0 AND s.action_request = ''
           AND (s.last_auto IS NULL OR s.last_auto < NOW() - INTERVAL " . WPM_AUTO_INTERVAL . ' SECOND
             OR (s.vulns_fixable > 0 AND s.last_auto < NOW() - INTERVAL 1 HOUR))
         ORDER BY s.vulns_fixable > 0 DESC, s.last_auto IS NOT NULL, s.last_auto',
        $conf['server_id'],
    ) : [];

    foreach ($candidates ?: [] as $site) {
        $report = json_decode((string)$site['updates_json'], true);
        if (!is_array($report) || !isset($report['plugins']) || !AutoUpdatePolicy::shouldRun($site, $report, $enabled)) {
            continue;
        }
        if ($distrust($site) !== '') {
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
            json_encode($listBackups($site), JSON_THROW_ON_ERROR),
            $rolledBack ? 1 : 0,
            AutoUpdatePolicy::fingerprint($report),
            $site['wpm_site_id'],
        );
        $event($site, 'auto_update', $result['outcome'], $result['message']);
        break;
    }
}

// The summary: the first node that records its name sends it, in the time of the master database.
if (!$config->isDryRun()) {
    $frequency = (string)$settings->get('notifications.summary');
    $now = (int)($app->dbmaster->queryOneRecord('SELECT UNIX_TIMESTAMP() AS now')['now'] ?? time());
    $key = Notifications::summaryKey($frequency, $now);
    if ($key !== '') {
        $app->dbmaster->query('INSERT IGNORE INTO wpm_notice (notice) VALUES (?)', $key);
        if ($app->dbmaster->affectedRows() === 1) {
            $sites = $app->dbmaster->queryAllRecords(
                "SELECT IF(subfolder = '', domain, CONCAT(domain, '/', subfolder)) AS domain, status, status_message, action_status, action_message, updates_count, vulns_count, vulns_fixable FROM wpm_site ORDER BY domain, subfolder",
            );
            $events = $app->dbmaster->queryAllRecords(
                'SELECT domain, kind, outcome, message, created FROM wpm_event WHERE created >= FROM_UNIXTIME(?) ORDER BY created',
                $now - Notifications::summaryPeriod($frequency),
            );
            $mail = Notifications::summary($frequency, $sites ?: [], $events ?: []);
            if ($mail !== null) {
                $sendMail($mail);
            }
            $app->dbmaster->query('DELETE FROM wpm_event WHERE created < NOW() - INTERVAL ' . AuditLog::RETENTION_DAYS . ' DAY');
        }
    }
}
