<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

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
     * @param array $site the wpm_site record (with encrypted secrets)
     * @return list<string> executed commands, secrets masked
     */
    public function install(array $site, ?Runner $runner = null): array
    {
        $webRoot = $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web');
        if (!$this->guard->isPristineWebRoot($webRoot)) {
            throw new \RuntimeException("Refusing to install: {$webRoot} is not empty");
        }

        $dbPassword = $this->crypto->decrypt((string)$site['secret_db']);
        $wpPassword = $this->crypto->decrypt((string)$site['secret_wp']);
        $runner ??= new Runner($this->config->isDryRun(), [$dbPassword, $wpPassword]);

        $wp = new WpCli($this->config->get('wp_cli'), $webRoot, (string)$site['system_user']);
        $wpCfg = $this->config->get('wordpress');
        $domain = Validator::domain((string)$site['domain']);
        $scheme = $this->siteScheme();

        // ISPConfig placeholder pages: they could shadow index.php and must not stay public
        foreach (['index.html', 'standard_index.html'] as $placeholder) {
            if (is_file($webRoot . '/' . $placeholder)) {
                $runner->mustRun(new Command(['rm', '-f', '--', $webRoot . '/' . $placeholder], (string)$site['system_user']));
            }
        }

        $runner->mustRun($wp->coreDownload((string)$wpCfg['locale']));
        $runner->mustRun($wp->configCreate((string)$site['db_name'], (string)$site['db_user'], $dbPassword, (string)$site['db_host']));
        $runner->mustRun(new Command(['chmod', '0640', '--', $webRoot . '/wp-config.php'], (string)$site['system_user']));
        $runner->mustRun($wp->coreInstall(
            $scheme . '://' . $domain,
            (string)$site['wp_title'],
            (string)$site['wp_admin_user'],
            $wpPassword,
            (string)$site['wp_admin_email'],
        ));

        foreach ($wpCfg['constants'] as $name => [$value, $raw]) {
            $runner->mustRun($wp->configSet((string)$name, (string)$value, (bool)$raw));
        }
        foreach ($wpCfg['plugins'] as $slug) {
            $runner->mustRun($wp->pluginInstall((string)$slug));
        }
        if (!empty($wpCfg['theme'])) {
            $runner->mustRun($wp->themeInstall((string)$wpCfg['theme']));
        }

        return $runner->log();
    }

    /** https only when a certificate will exist: nothing answers on 443 without one. */
    private function siteScheme(): string
    {
        $d = $this->config->get('site_defaults');
        return $d['ssl'] === 'y' && $d['ssl_letsencrypt'] === 'y' ? 'https' : 'http';
    }
}
