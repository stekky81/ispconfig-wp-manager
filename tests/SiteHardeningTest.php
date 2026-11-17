<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests;

use PHPUnit\Framework\TestCase;
use WPStation\Config;
use WPStation\HealthCheck;
use WPStation\PathGuard;
use WPStation\Runner;
use WPStation\SiteHardening;

final class SiteHardeningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpstation-hard-' . bin2hex(random_bytes(4));
        mkdir($base . '/client1/web7/web/wp-content', 0o755, true);
        $this->root = (string)realpath($base);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function site(): array
    {
        return ['document_root' => $this->root . '/client1/web7', 'system_user' => 'web7', 'domain' => 'a.test', 'site_url' => 'http://a.test'];
    }

    private function hardening(): SiteHardening
    {
        return new SiteHardening(Config::fromArray([]), new PathGuard($this->root), new HealthCheck(static fn(): array => ['code' => 200, 'body' => ''], '127.0.0.1', 0));
    }

    private const WORDPRESS = "# BEGIN WordPress\nRewriteEngine On\nRewriteRule . /index.php [L]\n# END WordPress\n";

    public function testABlockGoesFirstAndTheRulesOfWordPressStay(): void
    {
        $with = SiteHardening::withBlock(self::WORDPRESS, 'xmlrpc');

        self::assertStringStartsWith("# BEGIN ispconfig-wpstation xmlrpc\n<Files \"xmlrpc.php\">", $with);
        self::assertStringEndsWith(self::WORDPRESS, $with);
        self::assertSame($with, SiteHardening::withBlock($with, 'xmlrpc'), 'once only');
        self::assertSame(self::WORDPRESS, SiteHardening::withoutBlock($with, 'xmlrpc'));
    }

    public function testBlocksOfDifferentMeasuresLiveTogether(): void
    {
        $both = SiteHardening::withBlock(SiteHardening::withBlock(self::WORDPRESS, 'xmlrpc'), 'sensitive_files');
        $one = SiteHardening::withoutBlock($both, 'sensitive_files');

        self::assertStringContainsString('xmlrpc.php', $one);
        self::assertStringNotContainsString('readme', $one);
        self::assertSame(self::WORDPRESS, SiteHardening::withoutBlock($one, 'xmlrpc'));
        self::assertSame('', SiteHardening::withoutBlock(SiteHardening::withBlock('', 'uploads_php'), 'uploads_php'));
    }

    public function testTheFileEditorIsAConstant(): void
    {
        $runner = new Runner(true);
        $this->hardening()->set($this->site(), 'file_edit', true, $runner);
        self::assertStringContainsString("'config' 'set' 'DISALLOW_FILE_EDIT' 'true' '--type=constant' '--raw'", implode("\n", $runner->log()));

        $runner = new Runner(true);
        $this->hardening()->set($this->site(), 'file_edit', false, $runner);
        self::assertStringContainsString("'config' 'delete' 'DISALLOW_FILE_EDIT'", implode("\n", $runner->log()));
    }

    public function testUploadsGetTheirOwnHtaccessAsTheSiteUser(): void
    {
        $runner = new Runner(true);
        $result = $this->hardening()->set($this->site(), 'uploads_php', true, $runner);
        $log = implode("\n", $runner->log());

        self::assertSame('done', $result['outcome']);
        self::assertStringContainsString("'-u' 'web7' '-H' '--' 'mkdir' '-p' '--' '{$this->root}/client1/web7/web/wp-content/uploads'", $log);
        self::assertStringContainsString("'-u' 'web7' '-H' '--' 'tee' '--' '{$this->root}/client1/web7/web/wp-content/uploads/.htaccess'", $log);
    }

    public function testALinkedUploadsFolderIsNotWritten(): void
    {
        symlink('/tmp', $this->root . '/client1/web7/web/wp-content/uploads');
        $result = $this->hardening()->set($this->site(), 'uploads_php', true, new Runner(true));

        self::assertNotSame('done', $result['outcome']);
        self::assertStringContainsString('is a link', $result['message']);
    }

    public function testAnUnknownMeasureIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->hardening()->set($this->site(), 'everything', true, new Runner(true));
    }
}
