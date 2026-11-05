<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Creates the ISPConfig objects for a new WordPress site through the Remote API:
 * website → database user → database → SFTP or FTP user.
 *
 * If a step fails, the objects already created are deleted again (in reverse order),
 * so a failed attempt does not leave half-configured sites behind.
 *
 * The parameter sets were tested on ISPConfig 3.3.2.
 */
final class SiteProvisioner
{
    /** @var list<array{method:string, id:int}> created objects, for rollback */
    private array $created = [];

    public function __construct(
        private readonly IspconfigApi $api,
        private readonly Config $config,
        private readonly Crypto $crypto,
    ) {
    }

    /**
     * @return array<string, mixed> the values to store in the wpm_site record
     */
    public function provision(SiteRequest $req): array
    {
        $this->created = [];
        $d = $this->config->get('site_defaults');
        $wp = $this->config->get('wordpress');

        $this->assertDomainIsFree($req->domain);

        $dbPassword = Crypto::randomPassword();
        $accessPassword = Crypto::randomPassword();
        $wpPassword = Crypto::randomPassword();

        $prefix = str_replace('[CLIENT_ID]', (string)$req->clientId, (string)$d['db_prefix']);
        $base = Validator::dbNameFromDomain($req->domain);
        $dbName = Validator::dbName(substr($prefix . $base, 0, 32));
        $dbUser = Validator::dbName(substr($prefix . $base, 0, 30) . 'u');
        $accessUser = Validator::dbName(substr($prefix . $base, 0, 30) . 'f');
        $dbServerId = $d['database_server_id'] ?? $req->serverId;

        $this->assertNamesAreFree($req, $dbServerId, $dbName, $dbUser, $accessUser);

        try {
            $domainId = (int)$this->step('sites_web_domain_add', 'sites_web_domain_delete', [
                'client_id' => $req->clientId,
                'params' => [
                    'server_id' => $req->serverId,
                    'ip_address' => '*',
                    'domain' => $req->domain,
                    'type' => 'vhost',
                    'parent_domain_id' => 0,
                    'vhost_type' => 'name',
                    'hd_quota' => $d['hd_quota'],
                    'traffic_quota' => $d['traffic_quota'],
                    'subdomain' => $d['subdomain'],
                    'php' => $d['php'],
                    'server_php_id' => $req->phpVersionId ?? $d['server_php_id'],
                    'pm' => 'ondemand',
                    'pm_process_idle_timeout' => 10,
                    'pm_max_requests' => 0,
                    'ssl' => $d['ssl'],
                    'ssl_letsencrypt' => $d['ssl_letsencrypt'],
                    'suexec' => 'y',
                    'allow_override' => 'All',
                    'http_port' => 80,
                    'https_port' => 443,
                    'active' => 'y',
                ],
                'readonly' => false,
            ]);

            $site = $this->api->call('sites_web_domain_get', ['primary_id' => $domainId]);
            $systemUser = Validator::systemUser((string)($site['system_user'] ?? ''));
            $systemGroup = (string)($site['system_group'] ?? '');
            $documentRoot = (string)($site['document_root'] ?? '');
            if ($documentRoot === '' || $systemGroup === '') {
                throw new ApiException('sites_web_domain_get', 'website has no document root or group');
            }

            $dbUserId = (int)$this->step('sites_database_user_add', 'sites_database_user_delete', [
                'client_id' => $req->clientId,
                'params' => [
                    'server_id' => $dbServerId,
                    'database_user' => $dbUser,
                    'database_password' => $dbPassword,
                ],
            ]);

            $this->step('sites_database_add', 'sites_database_delete', [
                'client_id' => $req->clientId,
                'params' => [
                    'server_id' => $dbServerId,
                    'parent_domain_id' => $domainId,
                    'type' => 'mysql',
                    'database_name' => $dbName,
                    'database_user_id' => $dbUserId,
                    'database_ro_user_id' => 0,
                    'database_charset' => 'utf8mb4',
                    'remote_access' => 'n',
                    'remote_ips' => '',
                    'backup_interval' => 'none',
                    'backup_copies' => 1,
                    'active' => 'y',
                ],
            ]);

            $this->addAccessUser($req, $domainId, $accessUser, $accessPassword, $systemUser, $systemGroup, $documentRoot);
        } catch (\Throwable $e) {
            throw new ProvisioningException($e->getMessage(), $this->rollback(), $e);
        }

        return [
            'domain' => $req->domain,
            'site_url' => ($d['ssl'] === 'y' && $d['ssl_letsencrypt'] === 'y' ? 'https' : 'http') . '://' . $req->domain,
            'server_id' => $req->serverId,
            'client_id' => $req->clientId,
            'domain_id' => $domainId,
            'system_user' => $systemUser,
            'document_root' => $documentRoot,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_host' => $d['db_host'],
            'access_type' => $req->accessType,
            'access_user' => $accessUser,
            'wp_title' => $req->title,
            'wp_admin_user' => $req->adminUser,
            'wp_admin_email' => $req->adminEmail,
            'wp_locale' => $req->locale ?? $wp['locale'],
            'wp_plugins' => implode(',', $req->plugins ?? $wp['plugins']),
            'wp_theme' => $req->theme ?? (string)$wp['theme'],
            'secret_db' => $this->crypto->encrypt($dbPassword),
            'secret_wp' => $this->crypto->encrypt($wpPassword),
            'secret_access' => $this->crypto->encrypt($accessPassword),
            'status' => 'queued',
        ];
    }

    /** Password login only for now: SFTP keys are on the roadmap. */
    private function addAccessUser(
        SiteRequest $req,
        int $domainId,
        string $username,
        string $password,
        string $systemUser,
        string $systemGroup,
        string $documentRoot,
    ): void {
        $params = [
            'server_id' => $req->serverId,
            'parent_domain_id' => $domainId,
            'username' => $username,
            'password' => $password,
            'quota_size' => -1,
            'active' => 'y',
            'dir' => $documentRoot,
        ];

        if ($req->accessType === 'ftp') {
            $this->step('sites_ftp_user_add', 'sites_ftp_user_delete', [
                'client_id' => $req->clientId,
                'params' => $params + ['uid' => $systemUser, 'gid' => $systemGroup],
            ]);
            return;
        }

        $this->step('sites_shell_user_add', 'sites_shell_user_delete', [
            'client_id' => $req->clientId,
            'params' => $params + [
                'puser' => $systemUser,
                'pgroup' => $systemGroup,
                'shell' => '/bin/bash',
                'chroot' => 'jailkit',
            ],
        ]);
    }

    private function assertDomainIsFree(string $domain): void
    {
        $existing = $this->api->call('sites_web_domain_get', ['primary_id' => ['domain' => $domain]]);
        if (!empty($existing)) {
            throw new ValidationException("The domain {$domain} already exists in ISPConfig");
        }
    }

    /** ISPConfig would refuse these too, but only after the website was created. */
    private function assertNamesAreFree(SiteRequest $req, mixed $dbServerId, string $dbName, string $dbUser, string $accessUser): void
    {
        $filter = fn(string $field, string $value, array $more = []): array => ['primary_id' => [$field => $value] + $more];
        $checks = [
            ["database {$dbName}", 'sites_database_get', $filter('database_name', $dbName, ['server_id' => $dbServerId])],
            ["database user {$dbUser}", 'sites_database_user_get', $filter('database_user', $dbUser)],
            $req->accessType === 'ftp'
                ? ["FTP user {$accessUser}", 'sites_ftp_user_get', $filter('username', $accessUser)]
                : ["SFTP user {$accessUser}", 'sites_shell_user_get', $filter('username', $accessUser)],
        ];
        foreach ($checks as [$label, $method, $params]) {
            if (!empty($this->api->call($method, $params))) {
                throw new ValidationException("The {$label} already exists in ISPConfig");
            }
        }
    }

    private function step(string $addMethod, string $deleteMethod, array $params): mixed
    {
        $id = $this->api->call($addMethod, $params);
        if (!is_numeric($id) || (int)$id < 1) {
            throw new ApiException($addMethod, 'no id returned');
        }
        $this->created[] = ['method' => $deleteMethod, 'id' => (int)$id];
        return $id;
    }

    /** @return list<string> objects that could not be removed and need manual cleanup */
    private function rollback(): array
    {
        $leftovers = [];
        foreach (array_reverse($this->created) as $obj) {
            try {
                $this->api->call($obj['method'], ['primary_id' => $obj['id']]);
            } catch (\Throwable) {
                $leftovers[] = "{$obj['method']}#{$obj['id']}";
            }
        }
        $this->created = [];
        return $leftovers;
    }
}
