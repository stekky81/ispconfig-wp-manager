<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests\Integration;

use WpManager\Crypto;
use WpManager\SiteCloner;

/** A clone of site 901 into the empty site 902, with the database copied and the address replaced. */
final class SiteClonerTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // An earlier run left its clone: the target must be empty, as ISPConfig makes it.
        exec('find ' . escapeshellarg(self::web(902)) . ' -mindepth 1 -delete');
    }

    public function testTheCloneAnswersAtItsOwnAddressAndTheSourceStaysAsItWas(): void
    {
        $crypto = new Crypto(sodium_crypto_secretbox_keygen());
        $target = self::site(902) + ['clone_of' => 901, 'secret_db' => $crypto->encrypt((string)getenv('WPM_IT_DB_PASSWORD_902'))];
        self::wp(901, 'option', 'update', 'wpm_it_option', '{"url":"http://it901.test/page","mail":"me@it901.test.example"}', '--format=json');

        $log = (new SiteCloner(self::config(), $crypto, self::guard()))->clone($target, self::site(901));

        self::assertNotEmpty($log);
        self::assertSame('http://it902.test', self::wp(902, 'option', 'get', 'home'));
        self::assertSame('wpmit_web902', self::wp(902, 'config', 'get', 'DB_NAME'));
        // Inside serialized data, with the lengths right: WordPress reads it back.
        $option = json_decode(self::wp(902, 'option', 'get', 'wpm_it_option', '--format=json'), true);
        self::assertSame('http://it902.test/page', $option['url']);
        self::assertSame('http://it901.test', self::wp(901, 'option', 'get', 'home'));
        self::assertSame('0', self::wp(902, 'option', 'get', 'blog_public'), 'a clone asks search engines to stay away');
        self::assertSame('web902', posix_getpwuid((int)fileowner(self::web(902) . '/wp-config.php'))['name']);
    }
}
