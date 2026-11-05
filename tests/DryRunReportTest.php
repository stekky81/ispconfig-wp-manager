<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\Crypto;
use WpManager\DryRunReport;
use WpManager\DryRunTransport;
use WpManager\IspconfigApi;
use WpManager\SiteProvisioner;
use WpManager\SiteRequest;

final class DryRunReportTest extends TestCase
{
    public function testOneLinePerCallWithMethodAndParams(): void
    {
        $lines = DryRunReport::lines([
            ['method' => 'sites_web_domain_add', 'params' => ['client_id' => 1, 'params' => ['domain' => 'a.test/x']]],
            ['method' => 'logout', 'params' => []],
        ]);

        self::assertSame([
            'sites_web_domain_add {"client_id":1,"params":{"domain":"a.test/x"}}',
            'logout []',
        ], $lines);
    }

    // Regression: the dry-run calls were logged at debug level, which ISPConfig discards,
    // so the admin could not see them. The report is shown on the form: no secret may reach it.
    public function testFullProvisioningReportShowsEveryStepAndNoSecret(): void
    {
        $api = new IspconfigApi('https://panel.test/remote/json.php', 'u', 'api-password', new DryRunTransport());
        $crypto = new Crypto(base64_decode(Crypto::generateKey()));
        (new SiteProvisioner($api, Config::fromArray([]), $crypto))
            ->provision(new SiteRequest('shop.example.com', 3, 1, 'My shop', 'stefano', 'stefano@example.com'));

        $report = implode("\n", DryRunReport::lines($api->calls()));

        foreach (['sites_web_domain_add', 'sites_database_user_add', 'sites_database_add', 'sites_shell_user_add'] as $method) {
            self::assertStringContainsString($method, $report);
        }
        self::assertStringNotContainsString('api-password', $report);
        self::assertStringNotContainsString('dry-run-session', $report);
        self::assertSame(3, substr_count($report, '"********"') - substr_count($report, '"session_id":"********"'));
    }
}
