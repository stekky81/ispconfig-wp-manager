<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Tells whether a site answers. Results are compared before and after a change: a login page
 * moved on purpose fails both times, and that is not a problem of the update.
 */
final class HealthCheck
{
    /** Only what PHP and WordPress print themselves, not a page that talks about errors. */
    private const BROKEN_PAGE = '/<b>(Fatal|Parse) error<\/b>|PHP (Fatal|Parse) error:|Error establishing a database connection/';

    /** @var callable(string $url): array{code:int, body:string} */
    private $fetch;

    public function __construct(?callable $fetch = null, private readonly string $address = '127.0.0.1')
    {
        $this->fetch = $fetch ?? $this->curlFetch(...);
    }

    /** @return array{home:bool, login:bool} */
    public function run(array $site): array
    {
        $base = self::baseUrl($site);
        return [
            'home' => self::healthy(($this->fetch)($base . '/')),
            'login' => self::healthy(($this->fetch)($base . '/wp-login.php')),
        ];
    }

    /** @return list<string> what worked before and does not work now */
    public static function regressions(array $before, array $after): array
    {
        return array_keys(array_filter($before, static fn(bool $ok, string $check): bool => $ok && !($after[$check] ?? false), ARRAY_FILTER_USE_BOTH));
    }

    public static function baseUrl(array $site): string
    {
        return rtrim((string)($site['site_url'] ?? '') ?: 'http://' . $site['domain'], '/');
    }

    private static function healthy(array $answer): bool
    {
        return $answer['code'] >= 200 && $answer['code'] < 400 && !preg_match(self::BROKEN_PAGE, $answer['body']);
    }

    /** The site is on this node: ask it directly, whatever the public DNS says. */
    private function curlFetch(string $url): array
    {
        $parts = parse_url($url);
        $port = ($parts['scheme'] ?? 'http') === 'https' ? 443 : 80;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$this->address}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'ispconfig-wp-manager health check',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => is_string($body) ? $body : ''];
    }
}
