<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Full removal of a site: the website, everything ISPConfig attaches to it, its databases and the database
// users nobody else uses. The page lists all of it; the admin types the domain and confirms that there is no
// backup of the module. Deleted through the datalog, like the Sites module of ISPConfig does: the nodes do the rest.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wp-manager/lib/bootstrap.php';
require_once 'lib/wpm_audit.inc.php';

use WpManager\SiteRemoval;

$app->auth->check_module_permissions('wpmanager');

if ($_SESSION['s']['user']['typ'] !== 'admin') {
    $app->error('Only administrators can remove WordPress sites in this version.');
}

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', 'templates/wpm_site_remove.htm');

$lng_file = 'lib/lang/' . $app->functions->check_language($_SESSION['s']['language']) . '_wpm_site_remove.lng';
include $lng_file;
$app->tpl->setVar($wb);

$escape = static fn($value): string => $app->functions->htmlentities((string)$value);

$id = $app->functions->intval($_REQUEST['id'] ?? 0);

/** Everything read again at each request: what the admin confirms is what is deleted. */
$read = static function () use ($app, $id): array {
    $site = $app->db->queryOneRecord('SELECT * FROM wpm_site WHERE wpm_site_id = ?', $id);
    if (!is_array($site)) {
        return [null, null, '', [], []];
    }
    $domainId = (int)$site['domain_id'];
    $web = $app->db->queryOneRecord('SELECT domain_id, domain, type FROM web_domain WHERE domain_id = ?', $domainId);
    $web = is_array($web) ? $web : null;
    $stagings = $app->db->queryOneRecord('SELECT COUNT(*) AS n FROM wpm_site WHERE staging_of = ?', $id);
    $clones = $app->db->queryOneRecord("SELECT COUNT(*) AS n FROM wpm_site WHERE clone_of = ? AND status IN ('queued', 'installing')", $id);
    $refusal = SiteRemoval::refusal($site, $web, (int)$stagings['n'], (int)$clones['n']);
    if ($refusal !== '') {
        return [$site, $web, $refusal, [], []];
    }

    $children = [];
    foreach (SiteRemoval::CHILDREN as $table => [$key, $label]) {
        $extra = match ($table) {
            'web_domain' => ', `type`',
            'web_database' => ', `database_user_id`, `database_ro_user_id`',
            default => '',
        };
        $children[$table] = $app->db->queryAllRecords("SELECT `{$key}`, `{$label}`{$extra} FROM `{$table}` WHERE `parent_domain_id` = ?", $domainId) ?: [];
    }
    $children['web_folder_user'] = [];
    foreach ($children['web_folder'] as $folder) {
        $users = $app->db->queryAllRecords('SELECT web_folder_user_id, username FROM web_folder_user WHERE web_folder_id = ?', $folder['web_folder_id']);
        $children['web_folder_user'] = array_merge($children['web_folder_user'], $users ?: []);
    }
    $children['web_database_user'] = [];
    $elsewhere = [];
    foreach (SiteRemoval::databaseUsers($children['web_database']) as $userId) {
        $user = $app->db->queryOneRecord('SELECT database_user_id, database_user FROM web_database_user WHERE database_user_id = ?', $userId);
        if (is_array($user)) {
            $children['web_database_user'][] = $user;
        }
        $other = $app->db->queryOneRecord(
            'SELECT COUNT(*) AS n FROM web_database WHERE (database_user_id = ? OR database_ro_user_id = ?) AND parent_domain_id != ?',
            $userId,
            $userId,
            $domainId,
        );
        if ((int)$other['n'] > 0) {
            $elsewhere[] = $userId;
        }
    }
    return [$site, $web, '', SiteRemoval::plan($web, $children, $elsewhere), SiteRemoval::notAttached($site, $children['web_database'])];
};

[$site, $web, $refusal, $plan, $notAttached] = $read();
if (!is_array($site)) {
    $app->error($wb['site_not_found_txt']);
}

if (isset($_POST['confirm_domain'])) {
    $app->auth->csrf_token_check();
    if (trim((string)$_POST['confirm_domain']) !== (string)$site['domain'] || ($_POST['confirm_no_backup'] ?? '') !== 'y') {
        $app->tpl->setVar('error_msg', $escape($wb['confirm_error_txt']));
    } elseif ($refusal === '') {
        foreach ($plan as $item) {
            if ($item['table'] === 'web_database_user') {
                // As the Sites module does: server_id 0, so that every database server drops the user.
                $old = $app->db->queryOneRecord('SELECT * FROM web_database_user WHERE database_user_id = ?', $item['id']);
                if (is_array($old)) {
                    $app->db->query('DELETE FROM web_database_user WHERE database_user_id = ?', $item['id']);
                    $old['server_id'] = 0;
                    $app->db->datalogSave('web_database_user', 'DELETE', 'database_user_id', $item['id'], $old, []);
                }
            } else {
                $app->db->datalogDelete($item['table'], $item['key'], $item['id']);
            }
        }
        $app->db->datalogDelete('wpm_site', 'wpm_site_id', $id);
        $summary = implode(', ', array_map(static fn(array $item): string => "{$item['table']} {$item['label']}", $plan));
        wpm_audit($app, $site, 'remove', '', $summary);
        $app->db->query(
            "INSERT INTO wpm_event (wpm_site_id, server_id, domain, kind, outcome, message) VALUES (?, ?, ?, 'remove', 'done', ?)",
            $id,
            (int)$site['server_id'],
            (string)$site['domain'],
            mb_substr('Removed by ' . $_SESSION['s']['user']['username'] . ': ' . $summary, 0, 1000),
        );
        header('Location: wpm_site_list.php');
        exit;
    }
}

$labels = [];
foreach ($plan as $item) {
    $labels[] = ['what' => $escape($wb['table_' . $item['table'] . '_txt'] ?? $item['table']), 'name' => $escape($item['label'])];
}
$app->tpl->setVar('id', $id);
$app->tpl->setVar('domain', $escape($site['domain']));
$app->tpl->setVar('refusal', $escape($refusal));
$app->tpl->setLoop('plan', $labels);
$app->tpl->setVar('not_attached', $escape(implode(', ', $notAttached)));

$csrf_token = $app->auth->csrf_token_get('wpm_site_remove');
$app->tpl->setVar('_csrf_id', $csrf_token['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrf_token['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
