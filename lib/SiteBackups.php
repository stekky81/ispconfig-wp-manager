<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/** What an update needs from the backups of a site. */
interface SiteBackups
{
    /** @return string the id of the new backup */
    public function create(array $site, ?Runner $runner = null): string;

    public function restore(array $site, string $id, ?Runner $runner = null): void;
}
