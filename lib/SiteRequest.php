<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * The few values the admin types in the form. Everything else comes from the defaults.
 * Validated on construction, so an instance is always safe to use.
 */
final class SiteRequest
{
    public readonly string $domain;
    public readonly int $clientId;
    public readonly int $serverId;
    public readonly string $title;
    public readonly string $adminUser;
    public readonly string $adminEmail;
    public readonly string $accessType;
    /** Options left null come from the global defaults. */
    public readonly ?string $locale;
    /** @var list<string>|null */
    public readonly ?array $plugins;
    public readonly ?string $theme;
    public readonly ?int $phpVersionId;
    /** @var list<array{type:string, blob:string, comment:string}> public keys of the SFTP user */
    public readonly array $sshKeys;
    /** MB of web space, -1 = unlimited; null = the default of the settings */
    public readonly ?int $hdQuota;
    /** A folder of an existing website (example.com/blog); '' for a new website */
    public readonly string $subfolder;

    public function __construct(
        string $domain,
        mixed $clientId,
        mixed $serverId,
        string $title,
        string $adminUser,
        string $adminEmail,
        string $accessType = 'sftp',
        ?string $locale = null,
        ?string $plugins = null,
        ?string $theme = null,
        mixed $phpVersionId = null,
        string $sshKeys = '',
        ?int $hdQuota = null,
        string $subfolder = '',
    ) {
        $this->domain = Validator::domain($domain);
        $this->clientId = Validator::positiveId($clientId);
        $this->serverId = Validator::positiveId($serverId);
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new ValidationException('Site title must be 1-200 characters');
        }
        $this->title = $title;
        $this->adminUser = Validator::wpUser($adminUser);
        $this->adminEmail = Validator::email($adminEmail);
        $this->accessType = Validator::accessType($accessType);
        $this->locale = $locale === null ? null : Validator::locale($locale);
        $this->plugins = $plugins === null ? null : Validator::wpSlugList($plugins);
        $this->theme = $theme === null || $theme === '' ? $theme : Validator::wpSlug($theme);
        $this->phpVersionId = $phpVersionId === null ? null : Validator::phpVersionId($phpVersionId);
        $this->sshKeys = SshKeys::parse($sshKeys);
        if ($hdQuota !== null && $hdQuota !== -1 && $hdQuota < 1) {
            throw new ValidationException('Invalid web space: ' . $hdQuota);
        }
        $this->hdQuota = $hdQuota;
        $this->subfolder = $subfolder === '' ? '' : Subfolder::forNewSite($subfolder);
        if ($this->subfolder !== '' && ($this->sshKeys !== [] || $hdQuota !== null)) {
            throw new ValidationException('A site in a folder uses the users and the web space of its website');
        }
        if ($this->sshKeys !== [] && $this->accessType !== 'sftp') {
            throw new ValidationException('SSH keys work with SFTP only, not with FTP');
        }
    }
}
