<?php
// SPDX-License-Identifier: BSD-3-Clause
//
// Full removal of a site: the website, everything ISPConfig attaches to it, its databases and the database
// users nobody else uses. The page lists all of it; the admin types the domain and confirms that there is no
// backup of the module. Deleted through the datalog, like the Sites module of ISPConfig does: the nodes do the rest.

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once '/opt/ispconfig-wpstation/lib/bootstrap.php';
require_once 'lib/wpstation_audit.inc.php';
require_once 'lib/wpstation_scope.inc.php';

use WPStation\SiteRemoval;

$app->auth->check_module_permissions('wpstation');


$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', 'templates/wpstation_site_remove.htm');

$lng_file = 'lib/lang/' . $app->functions->check_language($_SESSION['s']['language']) . '_wpstation_site_remove.lng';
include $lng_file;
$app->tpl->setVar($wb);

$escape = static fn($value): string => $app->functions->htmlentities((string)$value);

$id = $app->functions->intval($_REQUEST['id'] ?? 0);

/** Everything read again at each request: what the admin confirms is what is deleted. */
$read = static function () use ($app, $id): array {
    // Clients and resellers: only with the delete permission on the website, as in the Sites module.
    $site = wpstation_site($app, $id, 'd');
    if (!is_array($site)) {
        return [null, null, '', [], []];
    }
    $domainId = (int)$site['domain_id'];
    $web = $app->db->queryOneRecord('SELECT domain_id, domain, type FROM web_domain WHERE domain_id = ?', $domainId);
    $web = is_array($web) ? $web : null;
    $stagings = $app->db->queryOneRecord('SELECT COUNT(*) AS n FROM wpstation_site WHERE staging_of = ?', $id);
    $clones = $app->db->queryOneRecord("SELECT COUNT(*) AS n FROM wpstation_site WHERE clone_of = ? AND status IN ('queued', 'installing')", $id);
    $folders = $app->db->queryOneRecord("SELECT COUNT(*) AS n FROM wpstation_site WHERE domain_id = ? AND subfolder != '' AND status != 'removed'", $domainId);
    $refusal = SiteRemoval::refusal($site, $web, (int)$stagings['n'], (int)$clones['n'], (int)$folders['n']);
    if ($refusal !== '') {
        return [$site, $web, $refusal, [], []];
    }

    // A site in a folder: its database, its users and its cron job here; the folder by its node. The website stays.
    if ((string)$site['subfolder'] !== '') {
        $databases = $app->db->queryAllRecords('SELECT database_id, database_name, database_user_id, database_ro_user_id FROM web_database WHERE parent_domain_id = ?', $domainId) ?: [];
        $own = SiteRemoval::ownDatabase($site, $databases);
        $users = [];
        $elsewhere = [];
        foreach (SiteRemoval::databaseUsers($own) as $userId) {
            $user = $app->db->queryOneRecord('SELECT database_user_id, database_user FROM web_database_user WHERE database_user_id = ?', $userId);
            if (is_array($user)) {
                $users[] = $user;
            }
            $other = $app->db->queryOneRecord(
                'SELECT COUNT(*) AS n FROM web_database WHERE (database_user_id = ? OR database_ro_user_id = ?) AND database_id != ?',
                $userId,
                $userId,
                (int)$own[0]['database_id'],
            );
            if ((int)$other['n'] > 0) {
                $elsewhere[] = $userId;
            }
        }
        $cron = (int)$site['cron_job_id'] > 0
            ? $app->db->queryOneRecord('SELECT id, command FROM cron WHERE id = ? AND parent_domain_id = ?', (int)$site['cron_job_id'], $domainId)
            : null;
        return [$site, $web, '', SiteRemoval::folderPlan($site, $databases, $users, $elsewhere, is_array($cron) ? $cron : null), SiteRemoval::notAttached($site, $databases)];
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
    if (trim((string)$_POST['confirm_domain']) !== \WPStation\Subfolder::label($site) || ($_POST['confirm_no_backup'] ?? '') !== 'y') {
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
        $inFolder = (string)$site['subfolder'] !== '';
        if ($inFolder) {
            // The node deletes the folder, then marks the record, which the list drops (wpstation_site_list.php).
            $app->db->datalogUpdate('wpstation_site', ['action_request' => 'remove_folder', 'action_param' => '', 'action_status' => 'requested', 'action_message' => ''], 'wpstation_site_id', $id);
            $plan[] = ['table' => 'folder', 'label' => 'web/' . $site['subfolder']];
        } else {
            $app->db->datalogDelete('wpstation_site', 'wpstation_site_id', $id);
        }
        $summary = implode(', ', array_map(static fn(array $item): string => "{$item['table']} {$item['label']}", $plan));
        wpstation_audit($app, $site, 'remove', '', $summary);
        $app->db->query(
            "INSERT INTO wpstation_event (wpstation_site_id, server_id, domain, kind, outcome, message) VALUES (?, ?, ?, 'remove', 'done', ?)",
            $id,
            (int)$site['server_id'],
            \WPStation\Subfolder::label($site),
            mb_substr(($inFolder ? 'Removal asked by ' : 'Removed by ') . $_SESSION['s']['user']['username'] . ': ' . $summary, 0, 1000),
        );
        header('Location: ' . ($inFolder ? 'wpstation_site_view.php?id=' . $id : 'wpstation_site_list.php'));
        exit;
    }
}

$labels = [];
if ((string)$site['subfolder'] !== '' && $refusal === '') {
    $labels[] = ['what' => $escape($wb['table_folder_txt']), 'name' => $escape(rtrim((string)$site['document_root'], '/') . '/web/' . $site['subfolder'])];
}
foreach ($plan as $item) {
    $labels[] = ['what' => $escape($wb['table_' . $item['table'] . '_txt'] ?? $item['table']), 'name' => $escape($item['label'])];
}
$app->tpl->setVar('id', $id);
$app->tpl->setVar('domain', $escape(\WPStation\Subfolder::label($site)));
$app->tpl->setVar('in_folder', (string)$site['subfolder'] !== '' ? 1 : 0);
$app->tpl->setVar('refusal', $escape($refusal));
$app->tpl->setLoop('plan', $labels);
$app->tpl->setVar('not_attached', $escape(implode(', ', $notAttached)));

$csrf_token = $app->auth->csrf_token_get('wpstation_site_remove');
$app->tpl->setVar('_csrf_id', $csrf_token['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrf_token['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
