<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Updates a site with a way back: check, backup, update, check again, restore if it broke.
 * Runs on the web node, always as the site user.
 */
final class SiteUpdater
{
    public const SCOPES = ['all', 'core', 'plugins', 'themes'];

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
        private readonly SiteBackups $backups,
        private readonly HealthCheck $health,
    ) {
    }

    /** @return array{outcome:string, message:string} outcome: updated, refused, rolled_back or rollback_failed */
    public function update(array $site, string $scope, ?Runner $runner = null): array
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException("Invalid update scope: {$scope}");
        }
        $runner ??= new Runner($this->config->isDryRun(), [], 1800);

        $before = $this->health->run($site);
        if (!$before['home']) {
            return ['outcome' => 'refused', 'message' => 'The site does not answer correctly: not updated, nothing changed.'];
        }

        // If this throws nothing was changed.
        $backup = $this->backups->create($site, $runner);

        try {
            $this->runUpdates($site, $scope, $runner);
        } catch (\RuntimeException $e) {
            return $this->rollback($site, $backup, $before, $runner, 'Update failed: ' . $e->getMessage());
        }

        $broken = HealthCheck::regressions($before, $this->health->run($site));
        if ($broken !== []) {
            return $this->rollback($site, $backup, $before, $runner, 'Broken after the update: ' . implode(', ', $broken));
        }
        return ['outcome' => 'updated', 'message' => "Updated ({$scope}). Backup {$backup} kept."];
    }

    private function runUpdates(array $site, string $scope, Runner $runner): void
    {
        $webRoot = $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web');
        $wp = new WpCli($this->config->get('wp_cli'), $webRoot, (string)$site['system_user']);

        if ($scope === 'all' || $scope === 'core') {
            $runner->mustRun($wp->coreUpdate());
            $runner->mustRun($wp->coreUpdateDb());
        }
        if ($scope === 'all' || $scope === 'plugins') {
            $runner->mustRun($wp->pluginUpdateAll());
        }
        if ($scope === 'all' || $scope === 'themes') {
            $runner->mustRun($wp->themeUpdateAll());
        }
    }

    private function rollback(array $site, string $backup, array $before, Runner $runner, string $reason): array
    {
        try {
            $this->backups->restore($site, $backup, $runner);
        } catch (\RuntimeException $e) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. Restore of backup {$backup} failed: " . $e->getMessage()];
        }
        $still = HealthCheck::regressions($before, $this->health->run($site));
        if ($still !== []) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. Backup {$backup} restored, but still broken: " . implode(', ', $still)];
        }
        return ['outcome' => 'rolled_back', 'message' => "{$reason}. Backup {$backup} restored."];
    }
}
