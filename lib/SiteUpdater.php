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
    /** What the panel can ask for. */
    public const SCOPES = ['all', 'core', 'plugins', 'themes', 'major'];
    /** Plugins, themes and minor core releases: the unattended update of the worker. */
    public const AUTOMATIC = 'automatic';

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
        if ($scope !== self::AUTOMATIC && !in_array($scope, self::SCOPES, true)) {
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

        $broken = $this->health->regressionsAfterChange($site, $before);
        if ($broken !== []) {
            return $this->rollback($site, $backup, $before, $runner, 'Broken after the update: ' . $this->health->describe($broken));
        }
        return ['outcome' => 'updated', 'message' => "Updated ({$scope}). Backup {$backup} kept."];
    }

    private function runUpdates(array $site, string $scope, Runner $runner): void
    {
        $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web');
        $siteRoot = $this->guard->assertInside((string)$site['document_root']);
        // Automatic updates start with nobody looking, and must-use plugins load during them.
        $wp = WpCli::sandboxed($this->config->get('wp_cli'), $this->config->get('php_cli'), $siteRoot, (string)$site['system_user']);

        // Only 'major' leaves the current branch of WordPress.
        if (in_array($scope, ['all', 'core', 'major', self::AUTOMATIC], true)) {
            $runner->mustRun($wp->coreUpdate($scope !== 'major'));
            $runner->mustRun($wp->coreUpdateDb());
        }
        if (in_array($scope, ['all', 'plugins', self::AUTOMATIC], true)) {
            $runner->mustRun($wp->pluginUpdateAll());
        }
        if (in_array($scope, ['all', 'themes', self::AUTOMATIC], true)) {
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
        $still = $this->health->regressionsAfterChange($site, $before);
        if ($still !== []) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. Backup {$backup} restored, but still broken: " . $this->health->describe($still)];
        }
        return ['outcome' => 'rolled_back', 'message' => "{$reason}. Backup {$backup} restored."];
    }
}
