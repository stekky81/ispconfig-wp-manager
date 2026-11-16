<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Administrators of a site: password reset and the temporary user of the one-click login.
 * Everything runs as the site user, in the PHP sandbox, without plugins and themes: no plugin sees
 * the passwords, and a broken plugin does not stop a reset.
 *
 * The login user is not changed after it logs in: the login cookie of WordPress holds a piece of the
 * password hash, so a new password would end the session too. It is deleted instead: UNUSED_MINUTES
 * after its creation if nobody logged in, USED_MINUTES after the login otherwise.
 */
final class SiteUsers
{
    public const LOGIN_PREFIX = 'wpm-login-';
    public const UNUSED_MINUTES = 15;
    public const USED_MINUTES = 120;
    private const MAX_ADMINS = 50;

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    /** @return list<array{id:int, login:string}> from `wp user list --format=json`, without the temporary users */
    public static function parseAdmins(string $output): array
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $data = json_decode((string)end($lines), true);
        $admins = [];
        foreach (is_array($data) ? array_slice($data, 0, self::MAX_ADMINS * 2) : [] as $row) {
            $id = is_array($row) && is_numeric($row['ID'] ?? null) ? (int)$row['ID'] : 0;
            $login = is_array($row) && is_string($row['user_login'] ?? null) ? mb_substr(mb_scrub($row['user_login'], 'UTF-8'), 0, 60) : '';
            if ($id > 0 && $login !== '' && !self::isTemporary($login)) {
                $admins[] = ['id' => $id, 'login' => $login];
            }
        }
        return array_slice($admins, 0, self::MAX_ADMINS);
    }

    public static function isTemporary(string $login): bool
    {
        return preg_match('/^' . preg_quote(self::LOGIN_PREFIX, '/') . '[0-9a-f]{8}$/', $login) === 1;
    }

    /** @return list<array{id:int, login:string}> */
    public function admins(array $site, ?Runner $runner = null): array
    {
        $runner ??= new Runner($this->config->isDryRun(), [], 120);
        return self::parseAdmins($runner->mustRun($this->wp($site)->userList('administrator')));
    }

    /**
     * A new random password for an administrator, and every session of that user closed.
     *
     * @return array{user:string, password:string}
     */
    public function resetPassword(array $site, int $userId, ?Runner $runner = null): array
    {
        $password = Crypto::randomPassword();
        $runner ??= new Runner($this->config->isDryRun(), [$password], 120);
        $login = '';
        foreach ($this->admins($site, $runner) as $admin) {
            if ($admin['id'] === $userId) {
                $login = $admin['login'];
            }
        }
        if ($login === '' && !$runner->isDryRun()) {
            throw new \RuntimeException("User {$userId} is not an administrator of {$site['domain']}");
        }
        $wp = $this->wp($site);
        $runner->mustRun($wp->userSetPassword((string)$userId, $password));
        $runner->mustRun($wp->userSessionsDestroy((string)$userId));
        return ['user' => $login, 'password' => $password];
    }

    /**
     * A new temporary administrator. The previous one of the site goes first: one at a time.
     *
     * @return array{user:string, password:string}
     */
    public function createLogin(array $site, string $previous = '', ?Runner $runner = null): array
    {
        $password = Crypto::randomPassword();
        $runner ??= new Runner($this->config->isDryRun(), [$password], 120);
        if ($previous !== '') {
            $this->removeLogin($site, $previous, $runner);
        }
        $login = self::LOGIN_PREFIX . bin2hex(random_bytes(4));
        // .invalid never receives mail; WP-CLI sends no email to a new user.
        $runner->mustRun($this->wp($site)->userCreateAdmin($login, $login . '@example.invalid', $password));
        return ['user' => $login, 'password' => $password];
    }

    public function hasSession(array $site, string $login, ?Runner $runner = null): bool
    {
        $runner ??= new Runner($this->config->isDryRun(), [], 120);
        $result = $runner->run($this->wp($site)->userSessionCount(self::temporary($login)));
        $lines = preg_split('/\R/', trim($result['stdout'])) ?: [];
        return $result['exit'] === 0 && (int)end($lines) > 0;
    }

    /**
     * Deleted, with what it wrote given to an administrator. Without one, it can no longer log in.
     *
     * @return string what was done, for the log
     */
    public function removeLogin(array $site, string $login, ?Runner $runner = null): string
    {
        $runner ??= new Runner($this->config->isDryRun(), [], 120);
        $wp = $this->wp($site);
        $login = self::temporary($login);
        if ($runner->run($wp->userGet($login))['exit'] !== 0) {
            return "{$login} was already gone";
        }
        $heir = $this->admins($site, $runner)[0]['id'] ?? 0;
        if ($heir > 0) {
            $runner->mustRun($wp->userDelete($login, $heir));
            return "{$login} deleted";
        }
        // Nobody to give its posts to: it stays, without a known password, a role or sessions.
        $password = Crypto::randomPassword(40);
        $quiet = new Runner($runner->isDryRun(), [$password], 120);
        $quiet->mustRun($wp->userSetPassword($login, $password));
        $runner->mustRun($wp->userSetRole($login, 'subscriber'));
        $runner->run($wp->userSessionsDestroy($login));
        return "{$login} kept as a subscriber with an unknown password: no other administrator to give its content to";
    }

    /** Only the users this class created are ever deleted. */
    private static function temporary(string $login): string
    {
        if (!self::isTemporary($login)) {
            throw new ValidationException("Not a temporary login: {$login}");
        }
        return $login;
    }

    private function wp(array $site): WpCli
    {
        $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web');
        $siteRoot = $this->guard->assertInside((string)$site['document_root']);
        return WpCli::sandboxed($this->config->get('wp_cli'), $this->config->get('php_cli'), $siteRoot, (string)$site['system_user']);
    }
}
