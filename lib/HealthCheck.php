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

    /** A page of a site is not trusted: only this much of it is read. */
    public const MAX_BODY = 2097152;

    /** @var callable(string $url): array{code:int, body:string} */
    private $fetch;

    /** @var array<string, string> why each failed check of the last run failed */
    private array $problems = [];

    /** @param int $settle seconds to wait after a change: opcache of PHP-FPM rechecks files every 2 seconds */
    public function __construct(?callable $fetch = null, private readonly string $address = '127.0.0.1', private readonly int $settle = 3)
    {
        $this->fetch = $fetch ?? $this->curlFetch(...);
    }

    /** @return array{home:bool, login:bool} */
    public function run(array $site): array
    {
        $this->problems = [];
        $base = self::baseUrl($site);
        return [
            'home' => $this->check('home', $base . '/'),
            'login' => $this->check('login', $base . '/wp-login.php'),
        ];
    }

    /**
     * After files changed, PHP can run the old code for a moment: wait, and look again at what
     * seems broken before calling it broken.
     *
     * @return list<string> what worked before and does not work now
     */
    public function regressionsAfterChange(array $site, array $before): array
    {
        sleep($this->settle);
        $broken = self::regressions($before, $this->run($site));
        if ($broken !== []) {
            sleep($this->settle);
            $broken = self::regressions($before, $this->run($site));
        }
        return $broken;
    }

    /**
     * A new site, installed or cloned: everything should answer. A theme or plugin can install
     * without errors and still break every page.
     *
     * @return string a warning for the panel, or '' when the site answers
     */
    public function warningAfterInstall(array $site): string
    {
        $broken = $this->regressionsAfterChange($site, ['home' => true, 'login' => true]);
        return $broken === [] ? '' : 'Warning: installed, but the site does not answer correctly: ' . $this->describe($broken) . '. Check the theme and the plugins.';
    }

    /** @param list<string> $checks names of failed checks, with what the last run saw */
    public function describe(array $checks): string
    {
        return implode(', ', array_map(fn(string $check): string => isset($this->problems[$check]) ? "{$check} ({$this->problems[$check]})" : $check, $checks));
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

    private function check(string $name, string $url): bool
    {
        $answer = ($this->fetch)($url);
        $code = (int)$answer['code'];
        if ($code < 200 || $code >= 400) {
            $this->problems[$name] = $code === 0 ? 'no answer' : "HTTP {$code}";
            return false;
        }
        if (preg_match(self::BROKEN_PAGE, (string)$answer['body'], $match)) {
            $this->problems[$name] = "HTTP {$code}, the page says \"{$match[0]}\"";
            return false;
        }
        return true;
    }

    public static function limited(string $body): string
    {
        return substr($body, 0, self::MAX_BODY);
    }

    /** The site is on this node: ask it directly, whatever the public DNS says. */
    private function curlFetch(string $url): array
    {
        $parts = parse_url($url);
        $port = ($parts['scheme'] ?? 'http') === 'https' ? 443 : 80;
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$this->address}"],
            // Stops reading at the limit: curl ends the transfer when less is taken than offered.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) > self::MAX_BODY ? 0 : strlen($chunk);
            },
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'ispconfig-wp-manager health check',
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => self::limited($body)];
    }
}
