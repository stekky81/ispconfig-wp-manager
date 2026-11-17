<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

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
     * @param int $subfolderSites other sites in subfolders of the same website: they go with the website
     */
    public static function refusal(array $site, ?array $web, int $stagings, int $clonesRunning, int $subfolderSites = 0): string
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
        if (Subfolder::of($site) === '' && $subfolderSites > 0) {
            return 'its website has WordPress sites in subfolders: remove them first';
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
     * What goes with a site in a subfolder, besides its folder: its database, if it is attached to the website, the
     * users of that database that no other database uses, and its cron job. The website and whatever else hangs on
     * it stay: they belong to the site in web/ and to the other folders too.
     *
     * @param list<array> $databases of the website: database_id, database_name, database_user_id, database_ro_user_id
     * @param list<array> $users database_user_id, database_user of the users of the database of the site
     * @param list<int> $usersElsewhere database users that another database also uses, of this website too
     * @param array|null $cron the cron job of the site (id, command), if it has one on this website
     * @return list<array{table:string, key:string, id:int, label:string}>
     */
    public static function folderPlan(array $site, array $databases, array $users, array $usersElsewhere, ?array $cron = null): array
    {
        $plan = [];
        if ($cron !== null && (int)($cron['id'] ?? 0) > 0) {
            $plan[] = ['table' => 'cron', 'key' => 'id', 'id' => (int)$cron['id'], 'label' => (string)($cron['command'] ?? '')];
        }
        foreach (self::ownDatabase($site, $databases) as $db) {
            $plan[] = ['table' => 'web_database', 'key' => 'database_id', 'id' => (int)$db['database_id'], 'label' => (string)$db['database_name']];
        }
        foreach ($users as $user) {
            $id = (int)($user['database_user_id'] ?? 0);
            if ($id > 0 && !in_array($id, array_map('intval', $usersElsewhere), true)) {
                $plan[] = ['table' => 'web_database_user', 'key' => 'database_user_id', 'id' => $id, 'label' => (string)($user['database_user'] ?? '')];
            }
        }
        return $plan;
    }

    /** The database of the website that WordPress of this site uses, as a list of zero or one. @return list<array> */
    public static function ownDatabase(array $site, array $databases): array
    {
        $name = (string)($site['db_name'] ?? '');
        return $name === '' ? [] : array_values(array_filter(
            $databases,
            static fn(array $db): bool => (string)($db['database_name'] ?? '') === $name,
        ));
    }

    /**
     * What the node deletes for a site in a subfolder, as the site user: the folder, its backups and its debug log.
     *
     * @return list<Command>
     */
    public static function folderCommands(array $site, PathGuard $guard, SiteBackup $backups): array
    {
        $name = Subfolder::of($site);
        if ($name === '') {
            throw new \RuntimeException('Only a site in a subfolder is removed by its node; a website goes through ISPConfig');
        }
        $user = (string)$site['system_user'];
        $root = $guard->assertInside(rtrim((string)$site['document_root'], '/'));
        $commands = [];
        $folder = $guard->assertInside("{$root}/web") . '/' . $name;
        // Gone already: the rest still goes. A link is refused by webRoot().
        if (file_exists($folder) || is_link($folder)) {
            $commands[] = new Command(['rm', '-rf', '--', Subfolder::webRoot($site, $guard)], $user);
        }
        $backupDir = "{$root}/tmp/wpstation-backups/subfolder-{$name}";
        if (is_dir($backupDir) && !is_link($backupDir) && $backups->directory($site) === $backupDir) {
            $commands[] = new Command(['rm', '-rf', '--', $backupDir], $user);
        }
        $commands[] = new Command(['rm', '-f', '--', "{$root}/" . WpDebug::log($site)], $user);
        return $commands;
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
