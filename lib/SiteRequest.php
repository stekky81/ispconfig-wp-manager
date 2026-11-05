<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

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

    public function __construct(
        string $domain,
        mixed $clientId,
        mixed $serverId,
        string $title,
        string $adminUser,
        string $adminEmail,
        string $accessType = 'sftp',
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
    }
}
