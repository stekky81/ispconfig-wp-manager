<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * Installs WordPress into a freshly created ISPConfig website. Runs on the web node.
 */
final class WordPressInstaller
{
    public function __construct(
        private readonly Config $config,
        private readonly Crypto $crypto,
        private readonly PathGuard $guard,
    ) {
    }

    /** True when ISPConfig has finished creating the website on this node. */
    public function isReady(array $site): bool
    {
        return is_dir(rtrim((string)$site['document_root'], '/') . '/web');
    }

    /**
     * @param array $site the wpstation_site record (with encrypted secrets)
     * @param bool $resume an earlier attempt of the same job wrote into the web root, which is not empty
     *                     because of it: every step runs again over those files (see wpstation-worker.php)
     * @return list<string> warnings first, then the executed commands, secrets masked
     */
    public function install(array $site, ?Runner $runner = null, bool $resume = false): array
    {
        if (!$resume && !$this->isTargetEmpty($site)) {
            $folder = rtrim((string)$site['document_root'], '/') . '/web' . (Subfolder::of($site) === '' ? '' : '/' . Subfolder::of($site));
            throw new \RuntimeException("Refusing to install: {$folder} is not empty: the module never installs over existing files");
        }

        $dbPassword = $this->crypto->decrypt((string)$site['secret_db']);
        $wpPassword = $this->crypto->decrypt((string)$site['secret_wp']);
        $runner ??= new Runner($this->config->isDryRun(), [$dbPassword, $wpPassword]);

        $webRoot = $this->target($site, $runner);
        $wp = $this->wp($site, $webRoot);
        $wpCfg = $this->config->get('wordpress');
        $domain = Validator::domain((string)$site['domain']);
        $url = (string)($site['site_url'] ?? '') ?: Subfolder::url($this->siteScheme() . '://' . $domain, Subfolder::of($site));

        // ISPConfig placeholder pages: they could shadow index.php and must not stay public. A subfolder has none.
        foreach (Subfolder::of($site) === '' ? ['index.html', 'standard_index.html'] : [] as $placeholder) {
            if (is_file($webRoot . '/' . $placeholder)) {
                $runner->mustRun(new Command(['rm', '-f', '--', $webRoot . '/' . $placeholder], (string)$site['system_user']));
            }
        }

        $step = static function (string $what, Command $command) use ($runner): void {
            try {
                $runner->mustRun($command);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException(FailureMessage::step($what, $e->getMessage()), 0, $e);
            }
        };
        $step('Download of WordPress', $wp->coreDownload((string)($site['wp_locale'] ?? $wpCfg['locale']), $resume));
        $step('Creation of wp-config.php', $wp->configCreate((string)$site['db_name'], (string)$site['db_user'], $dbPassword, (string)$site['db_host'], 'wp_', $resume));
        $step('Creation of wp-config.php', new Command(['chmod', '0640', '--', $webRoot . '/wp-config.php'], (string)$site['system_user']));
        // Again after an earlier attempt: WP-CLI says it is installed and changes nothing.
        $step('Installation of WordPress', $wp->coreInstall(
            $url,
            (string)$site['wp_title'],
            (string)$site['wp_admin_user'],
            $wpPassword,
            (string)$site['wp_admin_email'],
        ));

        foreach ($wpCfg['constants'] as $name => [$value, $raw]) {
            $step("Setting {$name} in wp-config.php", $wp->configSet((string)$name, (string)$value, (bool)$raw));
        }

        // WordPress works without them: the admin can add them later, so they only give warnings.
        $warnings = [];
        $optional = static function (string $what, Command $command) use ($runner, &$warnings): void {
            try {
                $runner->mustRun($command);
            } catch (\RuntimeException $e) {
                $warnings[] = 'Warning: ' . FailureMessage::step($what, $e->getMessage());
            }
        };
        // The record wins, also when empty: the admin may have removed a default from the form.
        $plugins = isset($site['wp_plugins']) ? Validator::wpSlugList((string)$site['wp_plugins']) : $wpCfg['plugins'];
        foreach ($plugins as $slug) {
            $optional("Installation of the plugin {$slug}", $wp->pluginInstall((string)$slug));
        }
        $theme = $site['wp_theme'] ?? $wpCfg['theme'];
        if (!empty($theme)) {
            $optional("Installation of the theme {$theme}", $wp->themeInstall((string)$theme));
        }

        // The summary first: the status message keeps only the beginning of a long log.
        return array_merge($warnings, $runner->log());
    }

    /**
     * The folder WordPress goes into holds nothing yet: web/ with only the files of ISPConfig, or a subfolder of web/
     * that is missing or empty. Anything else is a site or data of somebody.
     */
    public function isTargetEmpty(array $site): bool
    {
        $name = Subfolder::of($site);
        $web = $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web');
        if ($name === '') {
            return $this->guard->isPristineWebRoot($web);
        }
        $folder = "{$web}/{$name}";
        if (is_link($folder)) {
            return false;
        }
        return !file_exists($folder) || (is_dir($folder) && array_diff(scandir($folder) ?: ['?'], ['.', '..']) === []);
    }

    /** The folder of WordPress; a missing subfolder is created by the site user. */
    private function target(array $site, Runner $runner): string
    {
        $name = Subfolder::of($site);
        if ($name !== '') {
            $folder = $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web') . '/' . $name;
            if (!file_exists($folder) && !is_link($folder)) {
                $runner->mustRun(new Command(['mkdir', '-m', '0755', '--', $folder], (string)$site['system_user']));
                if ($runner->isDryRun()) {
                    return $folder;
                }
            }
        }
        return Subfolder::webRoot($site, $this->guard);
    }

    /** WP-CLI in the sandbox of the site: plugins and themes installed here run their code too. */
    private function wp(array $site, ?string $webRoot = null): WpCli
    {
        $siteRoot = $this->guard->assertInside(rtrim((string)$site['document_root'], '/'));
        return WpCli::sandboxed($this->config->get('wp_cli'), $this->config->get('php_cli'), $siteRoot, (string)$site['system_user'], $webRoot ?? Subfolder::webRoot($site, $this->guard));
    }

    /** The installed version, or '' when it cannot be read: a finished install must not fail for this. */
    public function installedVersion(array $site, ?Runner $runner = null): string
    {
        $runner ??= new Runner($this->config->isDryRun());
        $wp = $this->wp($site);
        try {
            $result = $runner->run($wp->coreVersion());
        } catch (\RuntimeException) {
            return '';
        }
        return $result['exit'] === 0 ? self::parseVersion($result['stdout']) : '';
    }

    public static function parseVersion(string $output): string
    {
        $version = trim($output);
        return preg_match('/^\d+\.\d+(\.\d+)?(-[A-Za-z0-9.]+)?$/', $version) ? $version : '';
    }

    /** For records without site_url. https only when a certificate will exist: nothing answers on 443 without one. */
    private function siteScheme(): string
    {
        $d = $this->config->get('site_defaults');
        return $d['ssl'] === 'y' && $d['ssl_letsencrypt'] === 'y' ? 'https' : 'http';
    }
}
