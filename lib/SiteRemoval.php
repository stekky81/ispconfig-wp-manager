<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Full removal of a site: the website and everything ISPConfig attaches to it, like the Sites module of
 * ISPConfig deletes it, and also its databases and the database users no other database uses. The panel
 * reads the records, this class decides what goes and in which order, and the panel deletes them through
 * the datalog. The nodes do the rest, as for any change of ISPConfig. There is no backup of the module:
 * after this only the backups of ISPConfig, or external ones, bring the site back.
 */
final class SiteRemoval
{
    /** Read by the panel with these columns, `parent_domain_id` = the website. */
    public const CHILDREN = [
        'web_domain' => ['domain_id', 'domain'],
        'ftp_user' => ['ftp_user_id', 'username'],
        'shell_user' => ['shell_user_id', 'username'],
        'cron' => ['id', 'command'],
        'webdav_user' => ['webdav_user_id', 'username'],
        'web_folder' => ['web_folder_id', 'path'],
        'web_backup' => ['backup_id', 'filename'],
        'web_database' => ['database_id', 'database_name'],
    ];

    /**
     * Why the site cannot be removed now; '' when it can.
     *
     * @param int $stagings staging sites of this site
     * @param int $clonesRunning clones of this site that are not installed yet
     */
    public static function refusal(array $site, ?array $web, int $stagings, int $clonesRunning): string
    {
        if (!in_array($site['status'] ?? '', ['installed', 'error'], true)) {
            return 'the site is still being installed';
        }
        if ((string)($site['action_request'] ?? '') !== '') {
            return 'an action is running on the site';
        }
        if ($stagings > 0) {
            return 'it has a staging site: remove the staging first';
        }
        if ($clonesRunning > 0) {
            return 'a clone of it is being installed';
        }
        if ($web === null || ($web['type'] ?? '') !== 'vhost') {
            return 'its website is no longer in ISPConfig';
        }
        if ((int)$web['domain_id'] !== (int)($site['domain_id'] ?? 0) || (string)$web['domain'] !== (string)($site['domain'] ?? '')) {
            return 'its record does not match the website in ISPConfig';
        }
        return '';
    }

    /**
     * What is deleted, in order: what hangs on the website first, the website last.
     *
     * @param array<string, list<array>> $children rows of each table of CHILDREN, plus `web_folder_user` (id, username)
     *        and `web_database_user` (database_user_id, database_user): the users of the databases of the site
     * @param list<int> $usersElsewhere database users that a database of another website also uses
     * @return list<array{table:string, key:string, id:int, label:string}>
     */
    public static function plan(array $web, array $children, array $usersElsewhere): array
    {
        $plan = [];
        $add = static function (string $table, string $key, array $rows, string $labelColumn) use (&$plan): void {
            foreach ($rows as $row) {
                $id = (int)($row[$key] ?? 0);
                if ($id > 0) {
                    $plan[] = ['table' => $table, 'key' => $key, 'id' => $id, 'label' => (string)($row[$labelColumn] ?? '')];
                }
            }
        };
        foreach (self::CHILDREN as $table => [$key, $label]) {
            if ($table === 'web_folder') {
                // The users of a protected folder go before their folder.
                $add('web_folder_user', 'web_folder_user_id', $children['web_folder_user'] ?? [], 'username');
            }
            if ($table === 'web_domain') {
                $rows = array_filter($children[$table] ?? [], static fn(array $row): bool => ($row['type'] ?? '') !== 'vhost');
                $add($table, $key, $rows, $label);
                continue;
            }
            $add($table, $key, $children[$table] ?? [], $label);
        }
        // After their databases, and only when no other database needs them.
        $users = array_filter(
            $children['web_database_user'] ?? [],
            static fn(array $row): bool => !in_array((int)($row['database_user_id'] ?? 0), array_map('intval', $usersElsewhere), true),
        );
        $add('web_database_user', 'database_user_id', $users, 'database_user');
        $add('web_domain', 'domain_id', [$web], 'domain');
        return $plan;
    }

    /**
     * Databases the record of the site names that are not attached to its website: they stay, and the page says so.
     *
     * @param list<array> $databases of the website
     * @return list<string>
     */
    public static function notAttached(array $site, array $databases): array
    {
        $name = (string)($site['db_name'] ?? '');
        if ($name === '' || in_array($name, array_map(static fn(array $db): string => (string)($db['database_name'] ?? ''), $databases), true)) {
            return [];
        }
        return [$name];
    }

    /** The database users of the site, from its databases. @return list<int> */
    public static function databaseUsers(array $databases): array
    {
        $ids = [];
        foreach ($databases as $db) {
            foreach (['database_user_id', 'database_ro_user_id'] as $column) {
                $id = (int)($db[$column] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }
}
