<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Email notices of the module: what to send, to whom and when. Sending is left to the worker,
 * which uses the mail settings of ISPConfig. Never a password in an email: it travels in clear
 * and stays in mailboxes and their backups.
 */
final class Notifications
{
    public const OFF = 'off';
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';
    public const FREQUENCIES = [self::OFF, self::DAILY, self::WEEKLY];

    /** Outcomes that cannot wait for the summary. */
    private const URGENT = ['error', 'failed', 'rolled_back', 'rollback_failed'];
    private const MAX_RECIPIENTS = 10;
    /** A summary is sent from this hour on, in the time of the master database. */
    private const SUMMARY_HOUR = 6;
    private const PREFIX = '[WordPress Manager] ';

    /**
     * The addresses of the setting, or the email of the ISPConfig admin when it is empty.
     *
     * @return list<string>
     */
    public static function recipients(string $setting, string $adminMail): array
    {
        $list = self::parseRecipients($setting);
        return $list !== [] ? $list : self::parseRecipients($adminMail);
    }

    /**
     * Commas, semicolons or spaces between addresses. Anything that is not one address is refused.
     *
     * @return list<string>
     */
    public static function parseRecipients(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\x00-\x20<>"]/', $address)) {
                throw new ValidationException("Invalid email address: {$address}");
            }
            $out[strtolower($address)] = $address;
        }
        if (count($out) > self::MAX_RECIPIENTS) {
            throw new ValidationException('At most ' . self::MAX_RECIPIENTS . ' email addresses');
        }
        return array_values($out);
    }

    public static function isUrgent(string $outcome): bool
    {
        return in_array($outcome, self::URGENT, true);
    }

    /**
     * A new site or clone is ready, or failed for good.
     *
     * @return array{subject:string, body:string}
     */
    public static function installed(array $site, string $outcome, string $message): array
    {
        $domain = (string)$site['domain'];
        $what = (int)($site['clone_of'] ?? 0) > 0 ? 'clone' : 'site';
        if ($outcome === 'error') {
            return [
                'subject' => self::PREFIX . "The new {$what} {$domain} was not installed",
                'body' => "The installation of {$domain} stopped with an error:\n\n" . self::text($message)
                    . "\n\nSee the page of the site in ISPConfig: WordPress > Sites > {$domain}.\n",
            ];
        }
        $body = "{$domain} is ready: " . self::siteUrl($site) . "\n\n";
        if ($outcome === 'warning' && $message !== '') {
            $body .= "It works, with warnings:\n\n" . self::text($message) . "\n\n";
        }
        $body .= "The passwords are on the page of the site in ISPConfig (WordPress > Sites > {$domain}), once only.\n"
            . "This email has none on purpose.\n";
        return [
            'subject' => self::PREFIX . ($outcome === 'warning' ? "{$domain} is ready, with warnings" : "{$domain} is ready"),
            'body' => $body,
        ];
    }

    /**
     * An action or an automatic update that needs the admin now.
     *
     * @return array{subject:string, body:string}
     */
    public static function alert(array $site, string $kind, string $outcome, string $message): array
    {
        $domain = (string)$site['domain'];
        $what = self::KINDS[$kind] ?? $kind;
        $state = match ($outcome) {
            'rolled_back' => 'was rolled back',
            'rollback_failed' => 'broke the site and the rollback did not repair it',
            default => 'failed',
        };
        return [
            'subject' => self::PREFIX . ($outcome === 'rollback_failed' ? 'URGENT: ' : '') . ucfirst($what) . " of {$domain} {$state}",
            'body' => ucfirst($what) . " of {$domain} {$state}.\n\n" . self::text($message)
                . "\n\nSite: " . self::siteUrl($site) . "\nPage in ISPConfig: WordPress > Sites > {$domain}\n",
        ];
    }

    /**
     * The name of the summary due at this time, or '' when none is: one per day, or one per ISO week from Monday.
     * The first node that records this name sends it.
     */
    public static function summaryKey(string $frequency, int $now): string
    {
        if ((int)date('G', $now) < self::SUMMARY_HOUR && ($frequency === self::DAILY || date('N', $now) === '1')) {
            return '';
        }
        return match ($frequency) {
            self::DAILY => 'summary-' . date('Y-m-d', $now),
            self::WEEKLY => 'summary-' . date('o-\WW', $now),
            default => '',
        };
    }

    /** Seconds covered by a summary. */
    public static function summaryPeriod(string $frequency): int
    {
        return $frequency === self::DAILY ? 86400 : 7 * 86400;
    }

    /**
     * Null when there is nothing to report: an empty summary teaches to ignore the full ones.
     *
     * @param list<array<string, mixed>> $sites  domain, status, action_status, action_message, updates_count
     * @param list<array<string, mixed>> $events domain, kind, outcome, message, created
     * @return array{subject:string, body:string}|null
     */
    public static function summary(string $frequency, array $sites, array $events): ?array
    {
        $problems = [];
        $updates = [];
        foreach ($sites as $site) {
            $domain = (string)$site['domain'];
            if (($site['status'] ?? '') === 'error') {
                $problems[] = "{$domain}: not installed. " . self::line((string)($site['status_message'] ?? ''));
            } elseif (in_array($site['action_status'] ?? '', ['failed', 'rolled_back', 'rollback_failed', 'warning'], true)) {
                $problems[] = "{$domain}: " . str_replace('_', ' ', (string)$site['action_status']) . '. ' . self::line((string)($site['action_message'] ?? ''));
            }
            if ((int)($site['updates_count'] ?? 0) > 0) {
                $updates[] = "{$domain}: " . (int)$site['updates_count'];
            }
        }
        $done = [];
        foreach ($events as $event) {
            $done[] = substr((string)$event['created'], 0, 16) . ' ' . $event['domain'] . ': '
                . (self::KINDS[$event['kind']] ?? $event['kind']) . ', ' . str_replace('_', ' ', (string)$event['outcome']);
        }
        if ($problems === [] && $updates === [] && $done === []) {
            return null;
        }

        $period = $frequency === self::DAILY ? 'Daily' : 'Weekly';
        $body = '';
        foreach ([
            ['Sites that need a look', $problems],
            [$frequency === self::DAILY ? 'In the last 24 hours' : 'In the last 7 days', $done],
            ['Updates available', $updates],
        ] as [$title, $lines]) {
            if ($lines !== []) {
                $body .= $title . "\n" . str_repeat('-', strlen($title)) . "\n" . implode("\n", $lines) . "\n\n";
            }
        }
        $counts = array_filter([
            $problems === [] ? '' : count($problems) . ' to look at',
            $updates === [] ? '' : count($updates) . ' with updates',
        ]);
        return [
            'subject' => self::PREFIX . "{$period} summary" . ($counts === [] ? '' : ': ' . implode(', ', $counts)),
            'body' => $body . "Details on the pages of the sites in ISPConfig: WordPress > Sites.\n",
        ];
    }

    private const KINDS = [
        'install' => 'installation',
        'clone' => 'clone',
        'update' => 'update',
        'auto_update' => 'automatic update',
        'backup' => 'backup',
        'restore' => 'restore',
        'refresh' => 'update of the staging from production',
        'push' => 'push of the staging to production',
        'wpcron' => 'WP-Cron switch',
    ];

    private static function siteUrl(array $site): string
    {
        return (string)($site['site_url'] ?? '') ?: 'http://' . $site['domain'];
    }

    /** Text of a site or of a command, as it goes into an email: no control characters, not too long. */
    private static function text(string $message): string
    {
        $clean = (string)preg_replace('/[^\P{C}\n\t]/u', '', mb_scrub($message, 'UTF-8'));
        return mb_strlen($clean) > 3000 ? mb_substr($clean, 0, 3000) . '…' : $clean;
    }

    private static function line(string $message): string
    {
        $one = trim((string)preg_replace('/\s+/', ' ', self::text($message)));
        return mb_strlen($one) > 200 ? mb_substr($one, 0, 200) . '…' : $one;
    }
}
